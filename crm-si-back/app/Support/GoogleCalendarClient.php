<?php

namespace App\Support;

use App\Models\GoogleCalendarConnection;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendarService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;

/**
 * Arma un cliente autenticado de Google Calendar API para una conexión de
 * usuario, refrescando el access token cuando expiró. Si el refresh falla
 * (revocación, password change), marca la conexión como needs_reauth.
 */
class GoogleCalendarClient
{
    public const CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar.events.owned';

    public const SCOPES = [
        'openid',
        'email',
        self::CALENDAR_SCOPE,
    ];

    /**
     * Override de tests: permite inyectar un Guzzle client con MockHandler
     * para simular respuestas de la API de Google (incl. errores 409) sin
     * pegarle a la red real. No usado en producción.
     */
    public static ?ClientInterface $testHttpClient = null;

    public static function make(?array $scopes = null): GoogleClient
    {
        $client = new GoogleClient;
        $client->setClientId(config('services.google_calendar.client_id'));
        $client->setClientSecret(config('services.google_calendar.client_secret'));
        $client->setRedirectUri(config('services.google_calendar.redirect_uri'));
        $client->setScopes($scopes ?? self::SCOPES);
        $client->setAccessType('offline');
        $client->setIncludeGrantedScopes(true);
        $client->setPrompt('consent');

        // Mismo cliente que arma el SDK por defecto (base_uri + sin excepciones
        // HTTP: el SDK mapea los códigos a Google\Service\Exception), con un
        // timeout explícito: el default del SDK no corta nunca.
        $client->setHttpClient(self::$testHttpClient ?? new GuzzleClient([
            'base_uri' => GoogleClient::API_BASE_PATH,
            'http_errors' => false,
            'timeout' => (float) config('services.google_calendar.http_timeout', 20),
        ]));

        return $client;
    }

    /**
     * Devuelve un servicio de Calendar autenticado para la conexión dada,
     * refrescando el token si hace falta. Null si la conexión no es utilizable.
     */
    public static function serviceFor(GoogleCalendarConnection $connection): ?GoogleCalendarService
    {
        if ($connection->status === 'needs_reauth') {
            return null;
        }

        if (! self::hasRequiredScopes($connection)) {
            self::markNeedsReauth($connection, 'missing_required_scope');

            return null;
        }

        $client = self::make();

        $accessToken = $connection->getDecryptedAccessToken();
        $refreshToken = $connection->getDecryptedRefreshToken();

        if (! $accessToken || ! $refreshToken) {
            return null;
        }

        // 'created' explícito: sin él, el SDK asume created=0 y considera el
        // token siempre expirado (isAccessTokenExpired), forzando un refresh
        // innecesario en cada request. expires_in=0 fuerza el refresh real.
        $expiresIn = $connection->token_expires_at
            ? max(0, now()->diffInSeconds($connection->token_expires_at, false))
            : 0;

        $client->setAccessToken([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $expiresIn,
            'created' => now()->getTimestamp(),
        ]);

        if ($client->isAccessTokenExpired()) {
            try {
                $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

                if (isset($newToken['error'])) {
                    self::markNeedsReauth($connection, $newToken['error']);

                    return null;
                }

                $connection->setEncryptedAccessToken($newToken['access_token']);
                $connection->token_expires_at = now()->addSeconds($newToken['expires_in'] ?? 3600);
                $connection->status = 'connected';
                $connection->save();

                $client->setAccessToken($newToken);
            } catch (\Exception $e) {
                Log::error('GoogleCalendarClient: refresh token failed', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
                self::markNeedsReauth($connection, $e->getMessage());

                return null;
            }
        }

        return new GoogleCalendarService($client);
    }

    public static function hasRequiredScopes(GoogleCalendarConnection $connection): bool
    {
        $grantedScopes = $connection->scopes ?? [];

        return in_array(self::CALENDAR_SCOPE, $grantedScopes, true);
    }

    public static function markNeedsReauth(GoogleCalendarConnection $connection, string $reason): void
    {
        Log::warning('GoogleCalendarClient: connection needs reauth', [
            'connection_id' => $connection->id,
            'reason' => $reason,
        ]);

        $connection->status = 'needs_reauth';
        $connection->save();
    }
}
