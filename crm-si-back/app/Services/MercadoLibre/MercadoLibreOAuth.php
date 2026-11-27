<?php

namespace App\Services\MercadoLibre;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Flujo OAuth (authorization code + PKCE) de la app de Mercado Libre. El state
 * es de un solo uso, expira a los 10 minutos y guarda quién inició la conexión
 * y el code_verifier.
 */
class MercadoLibreOAuth
{
    public const TOKEN_URL = 'https://api.mercadolibre.com/oauth/token';

    private const STATE_TTL_SECONDS = 600;

    private const TIMEOUT = 10;

    public function isConfigured(): bool
    {
        return filled(config('services.mercadolibre.client_id'))
            && filled(config('services.mercadolibre.client_secret'))
            && filled(config('services.mercadolibre.redirect_uri'));
    }

    public function authorizationUrl(User $user): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);

        Cache::put(self::cacheKey($state), [
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'code_verifier' => $verifier,
        ], self::STATE_TTL_SECONDS);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.mercadolibre.client_id'),
            'redirect_uri' => config('services.mercadolibre.redirect_uri'),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);

        return rtrim((string) config('services.mercadolibre.auth_url'), '/').'/authorization?'.$query;
    }

    /**
     * @return ?array{user_id: int, tenant_id: int, code_verifier: string} null si es inválido, usado o vencido.
     */
    public function consumeState(string $state): ?array
    {
        return Cache::pull(self::cacheKey($state));
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, user_id: int}
     *
     * @throws MercadoLibreException
     */
    public function exchangeCode(string $code, string $codeVerifier): array
    {
        return $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => config('services.mercadolibre.redirect_uri'),
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, user_id: int}
     *
     * @throws MercadoLibreException con reauth=true si ML ya no acepta el refresh token.
     */
    public function refresh(string $refreshToken): array
    {
        return $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * @param  array<string, string>  $params
     * @return array{access_token: string, refresh_token: string, expires_in: int, user_id: int}
     *
     * @throws MercadoLibreException
     */
    private function requestToken(array $params): array
    {
        try {
            $response = Http::asForm()->acceptJson()->timeout(self::TIMEOUT)->post(self::TOKEN_URL, [
                ...$params,
                'client_id' => config('services.mercadolibre.client_id'),
                'client_secret' => config('services.mercadolibre.client_secret'),
            ]);
        } catch (\Throwable $e) {
            throw new MercadoLibreException('No pudimos comunicarnos con Mercado Libre.', previous: $e);
        }

        if ($response->failed()) {
            Log::warning('Mercado Libre rechazó el pedido de token', [
                'grant_type' => $params['grant_type'],
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            // invalid_grant y compañía: el vendedor revocó el acceso o el
            // código/refresh token ya no sirve.
            $reauth = $response->status() >= 400 && $response->status() < 500;

            throw new MercadoLibreException('Mercado Libre no aceptó la autorización.', $response->status(), $reauth);
        }

        $token = $response->json();
        if (! is_string($token['access_token'] ?? null) || ! is_string($token['refresh_token'] ?? null)) {
            throw new MercadoLibreException('Mercado Libre no devolvió un token válido.', $response->status());
        }

        return [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_in' => (int) ($token['expires_in'] ?? 21600),
            'user_id' => (int) ($token['user_id'] ?? 0),
        ];
    }

    private static function cacheKey(string $state): string
    {
        return "mercadolibre_oauth_state:{$state}";
    }
}
