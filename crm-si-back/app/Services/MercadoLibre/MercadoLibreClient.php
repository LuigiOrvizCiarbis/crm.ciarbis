<?php

namespace App\Services\MercadoLibre;

use App\Models\MercadoLibreConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Cliente de la API de Mercado Libre con el token de la cuenta del tenant.
 * Renueva el access token (6 h) antes de que venza; como el refresh token es
 * de un solo uso, la renovación va bajo lock para que dos workers no gasten
 * el mismo.
 */
class MercadoLibreClient
{
    public const BASE_URL = 'https://api.mercadolibre.com';

    private const TIMEOUT = 15;

    private const REFRESH_MARGIN_SECONDS = 300;

    public function __construct(private readonly MercadoLibreOAuth $oauth) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws MercadoLibreException
     */
    public function get(MercadoLibreConnection $connection, string $path, array $query = []): array
    {
        return $this->request($connection, 'get', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     *
     * @throws MercadoLibreException
     */
    public function post(MercadoLibreConnection $connection, string $path, array $payload): array
    {
        return $this->request($connection, 'post', $path, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     *
     * @throws MercadoLibreException
     */
    public function put(MercadoLibreConnection $connection, string $path, array $payload): array
    {
        return $this->request($connection, 'put', $path, $payload);
    }

    /**
     * Cuenta dueña del token, antes de que exista la conexión (callback OAuth).
     *
     * @return array<mixed>
     *
     * @throws MercadoLibreException
     */
    public function me(string $accessToken): array
    {
        return $this->json($this->send(fn () => $this->http($accessToken)->get('/users/me')));
    }

    /**
     * @return array<mixed>
     *
     * @throws MercadoLibreException
     */
    public function site(string $accessToken, string $siteId): array
    {
        return $this->json($this->send(fn () => $this->http($accessToken)->get('/sites/'.rawurlencode($siteId))));
    }

    /**
     * Renueva el token aunque no haya vencido. El refresh token vence a los
     * ~6 meses y es de un solo uso: renovarlo seguido mantiene viva la
     * autorización de cuentas sin actividad (sync apagado, catálogo estable).
     *
     * @throws MercadoLibreException con reauth=true si ML ya no acepta la autorización.
     */
    public function keepAlive(MercadoLibreConnection $connection): void
    {
        $this->accessToken($connection, force: true);
    }

    /**
     * Quita el permiso de la app en la cuenta del vendedor. Una falla solo se
     * loguea: la conexión se borra igual de este lado.
     */
    public function revoke(MercadoLibreConnection $connection): void
    {
        try {
            $this->request($connection, 'delete', '/users/'.$connection->ml_user_id.'/applications/'.config('services.mercadolibre.client_id'));
        } catch (MercadoLibreException $e) {
            Log::warning('No se pudo revocar el acceso de Mercado Libre', [
                'tenant_id' => $connection->tenant_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     *
     * @throws MercadoLibreException
     */
    private function request(MercadoLibreConnection $connection, string $method, string $path, array $data = [], bool $retried = false): array
    {
        $token = $this->accessToken($connection, force: $retried);

        try {
            $response = $this->send(fn () => $this->http($token)->{$method}($path, $data), allowUnauthorized: ! $retried);
        } catch (MercadoLibreException $e) {
            if ($e->reauth) {
                $this->markNeedsReauth($connection);
            }

            throw $e;
        }

        // Un 401 con un token vigente: el vendedor cambió la contraseña o ML lo
        // invalidó antes de tiempo. Se renueva una vez y se reintenta.
        if ($response->status() === 401) {
            return $this->request($connection, $method, $path, $data, retried: true);
        }

        return $this->json($response);
    }

    /**
     * @throws MercadoLibreException
     */
    private function accessToken(MercadoLibreConnection $connection, bool $force = false): string
    {
        if (! $force && ! $this->expiresSoon($connection) && ($token = $connection->getDecryptedAccessToken())) {
            return $token;
        }

        return Cache::lock('mercadolibre_token:'.$connection->id, 30)->block(20, function () use ($connection, $force): string {
            $fresh = MercadoLibreConnection::withoutGlobalScopes()->findOrFail($connection->id);

            // Otro worker ya la renovó mientras esperábamos el lock (el token
            // encriptado cambia en cada renovación).
            $renewedMeanwhile = $fresh->getRawOriginal('access_token') !== $connection->getRawOriginal('access_token');
            if ((! $force || $renewedMeanwhile) && ! $this->expiresSoon($fresh) && ($token = $fresh->getDecryptedAccessToken())) {
                $connection->setRawAttributes($fresh->getAttributes(), sync: true);

                return $token;
            }

            $refreshToken = $fresh->getDecryptedRefreshToken();
            if (! $fresh->isConnected() || ! $refreshToken) {
                throw new MercadoLibreException('La cuenta de Mercado Libre no está conectada.', reauth: true);
            }

            try {
                $token = $this->oauth->refresh($refreshToken);
            } catch (MercadoLibreException $e) {
                if ($e->reauth) {
                    $this->markNeedsReauth($connection);
                }

                throw $e;
            }

            $fresh->setTokens($token['access_token'], $token['refresh_token'], $token['expires_in']);
            $fresh->save();
            $connection->setRawAttributes($fresh->getAttributes(), sync: true);

            return $token['access_token'];
        });
    }

    private function markNeedsReauth(MercadoLibreConnection $connection): void
    {
        MercadoLibreConnection::withoutGlobalScopes()
            ->whereKey($connection->id)
            ->where('status', MercadoLibreConnection::STATUS_CONNECTED)
            ->update(['status' => MercadoLibreConnection::STATUS_NEEDS_REAUTH, 'updated_at' => now()]);
        $connection->status = MercadoLibreConnection::STATUS_NEEDS_REAUTH;
    }

    private function expiresSoon(MercadoLibreConnection $connection): bool
    {
        return $connection->token_expires_at === null
            || $connection->token_expires_at->lte(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws MercadoLibreException
     */
    private function send(callable $call, bool $allowUnauthorized = false): Response
    {
        try {
            $response = $call();
        } catch (\Throwable $e) {
            Log::warning('No se pudo contactar a Mercado Libre', ['error' => $e->getMessage()]);

            throw new MercadoLibreException('No pudimos comunicarnos con Mercado Libre.', previous: $e);
        }

        if ($response->successful() || ($allowUnauthorized && $response->status() === 401)) {
            return $response;
        }

        if ($response->status() === 401) {
            throw new MercadoLibreException('Mercado Libre rechazó la autorización. Volvé a conectar la cuenta.', 401, reauth: true);
        }

        if ($response->status() >= 500) {
            Log::warning('Mercado Libre respondió con error', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);
        }

        throw new MercadoLibreException(self::errorMessage($response), $response->status());
    }

    /**
     * Mensaje legible de un error de ML: los "cause" de tipo error (los
     * warnings no impiden publicar) o, si no hay, el message general.
     */
    public static function errorMessage(Response $response): string
    {
        $causes = collect((array) $response->json('cause', []))
            ->filter(fn ($cause) => is_array($cause) && ($cause['type'] ?? 'error') === 'error' && is_string($cause['message'] ?? null))
            ->pluck('message')
            ->unique()
            ->values();

        $message = $causes->isNotEmpty()
            ? $causes->implode(' ')
            : (string) ($response->json('message') ?: $response->json('error') ?: 'Mercado Libre respondió con un error ('.$response->status().').');

        return Str::limit($message, 1000);
    }

    /**
     * @return array<mixed>
     */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT)
            ->baseUrl(self::BASE_URL);
    }
}
