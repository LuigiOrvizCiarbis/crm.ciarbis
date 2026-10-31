<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Helpers compartidos del flujo OAuth de Meta (Facebook Login), usados tanto
 * por el onboarding de WhatsApp como por el de Instagram. Ambos intercambian
 * el `code` del SDK por un user token y lo extienden a long-lived con
 * `fb_exchange_token`. Centraliza también el scrubbing de secretos en logs.
 */
class MetaOAuth
{
    /**
     * Intercambia el `code` del Facebook Login por un user access token
     * long-lived (extendido). Devuelve null si Meta rechaza el code.
     */
    public static function exchangeCodeForToken(string $code, ?string $redirectUri = null): ?string
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        try {
            // El canje debe usar el MISMO redirect_uri que el SDK usó al pedir el
            // code, o Meta rechaza con el error 36008. El Embedded Signup de
            // WhatsApp (config_id) no asocia redirect_uri, por eso allí es null.
            // FB.login clásico (Instagram) sí, y el front nos lo envía.
            $params = [
                'client_id' => config('services.facebook.app_id'),
                'client_secret' => config('services.facebook.app_secret'),
                'code' => $code,
            ];

            if ($redirectUri !== null) {
                $params['redirect_uri'] = $redirectUri;
            }

            $response = Http::timeout(10)->get("https://graph.facebook.com/{$version}/oauth/access_token", $params);

            if ($response->successful()) {
                $token = $response->json('access_token');

                return self::extendTokenToPermanent($token);
            }

            Log::error('MetaOAuth: token exchange failed', [
                'status' => $response->status(),
                'error' => self::describeMetaError($response->json()),
            ]);
        } catch (\Exception $e) {
            // getMessage() de Guzzle puede incluir la URL completa con
            // ?client_secret=...&code=..., por eso pasa por scrubMessage.
            Log::error('MetaOAuth: exception exchanging code for token', self::describeException($e));
        }

        return null;
    }

    /**
     * Extiende un user token de corta vida a long-lived (fb_exchange_token).
     * Si falla, devuelve el token original (fallback).
     */
    public static function extendTokenToPermanent(string $token): string
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        try {
            $response = Http::timeout(10)->get("https://graph.facebook.com/{$version}/oauth/access_token", [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('services.facebook.app_id'),
                'client_secret' => config('services.facebook.app_secret'),
                'fb_exchange_token' => $token,
            ]);

            if ($response->successful() && $response->json('access_token')) {
                return $response->json('access_token');
            }

            Log::warning('MetaOAuth: no se pudo extender el token a long-lived, se usa el de corta vida', [
                'status' => $response->status(),
                'error' => self::describeMetaError($response->json()),
            ]);
        } catch (\Exception $e) {
            Log::warning('MetaOAuth: excepción extendiendo token a long-lived', self::describeException($e));
        }

        return $token;
    }

    /**
     * Resume una excepción a campos seguros para loguear.
     * Evita getTraceAsString() (filtra args de método) y pasa el mensaje por
     * scrubMessage por si contiene URLs con secretos (Guzzle).
     *
     * @return array{exception: class-string<\Throwable>, message: string, file: string, line: int}
     */
    public static function describeException(\Throwable $e): array
    {
        return [
            'exception' => $e::class,
            'message' => self::scrubMessage($e->getMessage()),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    /**
     * Saca tokens y secretos de un mensaje libre antes de loguearlo.
     */
    public static function scrubMessage(string $message): string
    {
        $patterns = [
            '/(client_secret|access_token|code|bussines_token|business_token)=[^\s&"\']+/i' => '$1=[REDACTED]',
            '/Bearer\s+[A-Za-z0-9._\-]+/i' => 'Bearer [REDACTED]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $message) ?? '[unloggable]';
    }

    /**
     * Extrae solo los campos seguros de un error de Graph API.
     *
     * @param  array<string, mixed>|null  $body
     * @return array{code: int|null, type: string|null, subcode: int|null, message: string|null}
     */
    public static function describeMetaError(?array $body): array
    {
        return [
            'code' => data_get($body, 'error.code'),
            'type' => data_get($body, 'error.type'),
            'subcode' => data_get($body, 'error.error_subcode'),
            'message' => data_get($body, 'error.message'),
        ];
    }

    /**
     * Convierte la respuesta estructurada de Graph API en texto seguro para
     * persistir o mostrar. `describeMetaError()` se reserva para logs JSON.
     */
    public static function formatMetaError(?array $body): string
    {
        $error = self::describeMetaError($body);
        $identifiers = array_filter([
            $error['code'],
            $error['subcode'],
        ], static fn (mixed $value): bool => $value !== null);
        $prefix = $identifiers !== [] ? '[Meta '.implode('/', $identifiers).'] ' : '';
        $message = trim((string) ($error['message'] ?? ''));

        if ($message === '') {
            return trim($prefix.'Meta devolvió un error sin detalle.');
        }

        return $prefix.self::scrubMessage($message);
    }

    /**
     * Extrae el porcentaje de uso de cuota del header `X-App-Usage` de una
     * respuesta de Graph API. Ese header es la única forma de ver la cuota del
     * *business token del cliente* (usado en smb_app_data): el panel de rate
     * limits de la app mide otro contador (llamadas con el token de la app), que
     * puede estar sano mientras este header ya reporta el límite alcanzado.
     *
     * Formato del header: {"call_count":28,"total_time":25,"total_cputime":25},
     * cada valor 0-100. Se devuelve el máximo de los tres, que es el que
     * determina si la próxima llamada será rechazada.
     *
     * @see https://developers.facebook.com/docs/graph-api/overview/rate-limiting/
     */
    public static function parseAppUsage(Response $response): ?int
    {
        $header = $response->header('X-App-Usage');

        if (! $header) {
            return null;
        }

        $usage = json_decode($header, true);

        if (! is_array($usage)) {
            return null;
        }

        $values = array_filter([
            $usage['call_count'] ?? null,
            $usage['total_time'] ?? null,
            $usage['total_cputime'] ?? null,
        ], static fn (mixed $value): bool => is_numeric($value));

        return $values !== [] ? (int) max($values) : null;
    }
}
