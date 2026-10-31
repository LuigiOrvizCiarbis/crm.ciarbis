<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoogleCalendarConnection;
use App\Models\Scopes\TenantScope;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\GoogleCalendarClient;
use App\Support\GoogleCalendarOAuth;
use Google\Service\Oauth2;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GoogleCalendarConnectionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $connection = $request->user()->googleCalendarConnection;

        return response()->json(['data' => $this->transform($connection)]);
    }

    public function authorizationUrl(Request $request): JsonResponse
    {
        $connection = $request->user()->googleCalendarConnection;
        $needsCalendarOnly = $connection && ! GoogleCalendarClient::hasRequiredScopes($connection);
        $scopes = $needsCalendarOnly ? [GoogleCalendarClient::CALENDAR_SCOPE] : null;
        $state = GoogleCalendarOAuth::createState(
            $request->user(),
            $needsCalendarOnly ? 'calendar' : 'combined',
        );

        $client = GoogleCalendarClient::make($scopes);
        $client->setState($state);

        return response()->json(['url' => $client->createAuthUrl()]);
    }

    /**
     * Ruta pública (Google redirige al navegador sin credenciales Sanctum).
     * El `state` es el único vínculo de identidad: lo emitimos nosotros,
     * de un solo uso, y expira a los 10 minutos.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        $state = $request->query('state');
        $code = $request->query('code');

        if (! $state || ! $code) {
            return redirect()->away("{$frontendUrl}/configuracion?google_calendar=error");
        }

        $payload = GoogleCalendarOAuth::consumeState($state);

        if (! $payload) {
            return redirect()->away("{$frontendUrl}/configuracion?google_calendar=expired");
        }

        $user = User::withoutGlobalScope(TenantScope::class)->find($payload['user_id']);

        if (! $user || ! TenantMembership::active()
            ->where('tenant_id', $payload['tenant_id'])
            ->where('user_id', $user->id)
            ->exists()) {
            return redirect()->away("{$frontendUrl}/configuracion?google_calendar=error");
        }

        // OAuth callbacks do not have the request header, so restore the
        // workspace captured in the one-time state only in memory.
        $user->setAttribute('tenant_id', (int) $payload['tenant_id']);

        try {
            $client = GoogleCalendarClient::make();
            $token = $client->fetchAccessTokenWithAuthCode($code);

            if (isset($token['error'])) {
                Log::error('GoogleCalendarConnectionController: token exchange failed', ['error' => $token]);

                return redirect()->away("{$frontendUrl}/configuracion?google_calendar=error");
            }

            $grantedScopes = explode(' ', $token['scope'] ?? '');
            $canReadEmail = in_array('email', $grantedScopes, true)
                || in_array('https://www.googleapis.com/auth/userinfo.email', $grantedScopes, true);

            if ($canReadEmail) {
                $client->setAccessToken($token);
                $oauth2 = new Oauth2($client);
                $googleEmail = $oauth2->userinfo->get()->email;
            } else {
                $googleEmail = GoogleCalendarConnection::withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', $user->tenant_id)
                    ->where('user_id', $user->id)
                    ->value('google_email');
            }

            if (! $googleEmail) {
                throw new \RuntimeException('No se pudo identificar la cuenta de Google conectada.');
            }

            $connection = GoogleCalendarOAuth::upsertConnection($user, $token, $googleEmail);

            if (! GoogleCalendarClient::hasRequiredScopes($connection)) {
                if (($payload['phase'] ?? 'combined') === 'calendar') {
                    return redirect()->away("{$frontendUrl}/configuracion?google_calendar=scope_missing");
                }

                $calendarClient = GoogleCalendarClient::make([GoogleCalendarClient::CALENDAR_SCOPE]);
                $calendarClient->setState(GoogleCalendarOAuth::createState($user, 'calendar'));

                return redirect()->away($calendarClient->createAuthUrl());
            }
        } catch (\Exception $e) {
            Log::error('GoogleCalendarConnectionController: callback exception', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->away("{$frontendUrl}/configuracion?google_calendar=error");
        }

        return redirect()->away("{$frontendUrl}/configuracion?google_calendar=connected");
    }

    public function destroy(Request $request): JsonResponse
    {
        $connection = $request->user()->googleCalendarConnection;

        if ($connection) {
            $this->revokeToken($connection);
            $connection->delete();
        }

        return response()->json(['message' => 'Conexión con Google Calendar eliminada']);
    }

    private function revokeToken(GoogleCalendarConnection $connection): void
    {
        try {
            $client = GoogleCalendarClient::make();
            $token = $connection->getDecryptedAccessToken();

            if ($token) {
                $client->revokeToken($token);
            }
        } catch (\Exception $e) {
            Log::warning('GoogleCalendarConnectionController: revoke failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function transform(?GoogleCalendarConnection $connection): ?array
    {
        if (! $connection) {
            return null;
        }

        if ($connection->status === 'connected' && ! GoogleCalendarClient::hasRequiredScopes($connection)) {
            GoogleCalendarClient::markNeedsReauth($connection, 'missing_required_scope');
        }

        return [
            'google_email' => $connection->google_email,
            'status' => $connection->status,
            'connected_at' => $connection->created_at?->toIso8601String(),
        ];
    }
}
