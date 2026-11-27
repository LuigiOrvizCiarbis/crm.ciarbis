<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MercadoLibreConnection;
use App\Models\MercadoLibreListing;
use App\Models\MercadoLibreProduct;
use App\Models\Scopes\TenantScope;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\MercadoLibre\MercadoLibreCatalogSync;
use App\Services\MercadoLibre\MercadoLibreClient;
use App\Services\MercadoLibre\MercadoLibreException;
use App\Services\MercadoLibre\MercadoLibreOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Cuenta de vendedor de Mercado Libre del tenant: conexión OAuth, ajustes de
 * publicación y sync del catálogo. Reusa los permisos del grupo ecommerce.
 */
class MercadoLibreConnectionController extends Controller
{
    public function __construct(
        private readonly MercadoLibreOAuth $oauth,
        private readonly MercadoLibreClient $client,
        private readonly MercadoLibreCatalogSync $sync,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('woocommerce.view') && ! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        return response()->json(['data' => $this->transform(MercadoLibreConnection::forTenant($user->tenant_id))]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->authorizeManage($request);

        $validated = $request->validate([
            'sync_enabled' => ['sometimes', 'boolean'],
            'listing_type_id' => ['sometimes', Rule::in(MercadoLibreConnection::LISTING_TYPES)],
            'condition' => ['sometimes', Rule::in(MercadoLibreConnection::CONDITIONS)],
            'default_quantity' => ['sometimes', 'integer', 'min:1', 'max:9999'],
        ]);

        $connection = MercadoLibreConnection::forTenant($user->tenant_id);
        if (! $connection) {
            return response()->json(['message' => 'Conectá tu cuenta de Mercado Libre primero.'], 422);
        }

        $wasSyncing = $connection->isSyncing();
        $connection->fill($validated)->save();

        if (! $wasSyncing && $connection->isSyncing()) {
            $this->sync->dispatchAll($connection);
        }

        return response()->json(['data' => $this->transform($connection->fresh())]);
    }

    /**
     * Deja de sincronizar y quita el permiso de la app. Las publicaciones
     * siguen en Mercado Libre; si se reconecta la misma cuenta se retoman.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $this->authorizeManage($request);

        $connection = MercadoLibreConnection::forTenant($user->tenant_id);
        if ($connection && $connection->status !== MercadoLibreConnection::STATUS_DISCONNECTED) {
            if ($connection->isConnected()) {
                $this->client->revoke($connection);
            }

            $connection->clearTokens();
            $connection->status = MercadoLibreConnection::STATUS_DISCONNECTED;
            $connection->save();
        }

        return response()->json(['message' => 'Cuenta de Mercado Libre desconectada.']);
    }

    public function authorizationUrl(Request $request): JsonResponse
    {
        $user = $this->authorizeManage($request);

        if (! $this->oauth->isConfigured()) {
            return response()->json(['message' => 'La integración con Mercado Libre no está configurada en el servidor.'], 422);
        }

        return response()->json(['url' => $this->oauth->authorizationUrl($user)]);
    }

    /**
     * Ruta pública: Mercado Libre redirige el navegador sin credenciales
     * Sanctum. El state de un solo uso es el único vínculo con el usuario.
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $payload = $state === '' ? null : $this->oauth->consumeState($state);
        if (! $payload) {
            return $this->backToSettings('expired');
        }

        if ($request->filled('error')) {
            return $this->backToSettings('denied');
        }

        $code = (string) $request->query('code', '');
        $user = User::withoutGlobalScope(TenantScope::class)->find($payload['user_id']);
        $isMember = $user && TenantMembership::active()
            ->where('tenant_id', $payload['tenant_id'])
            ->where('user_id', $user->id)
            ->exists();

        if ($code === '' || ! $isMember) {
            return $this->backToSettings('error');
        }

        try {
            $token = $this->oauth->exchangeCode($code, $payload['code_verifier']);
            $account = $this->client->me($token['access_token']);
            $site = $this->client->site($token['access_token'], (string) ($account['site_id'] ?? ''));
        } catch (MercadoLibreException $e) {
            Log::warning('MercadoLibreConnectionController: callback failed', ['error' => $e->getMessage()]);

            return $this->backToSettings('error');
        }

        $connection = $this->storeConnection((int) $payload['tenant_id'], $user->id, $token, $account, $site);

        if ($connection->isSyncing()) {
            $this->sync->dispatchAll($connection);
        }

        return $this->backToSettings('connected');
    }

    public function sync(Request $request): JsonResponse
    {
        $user = $this->authorizeManage($request);

        $connection = MercadoLibreConnection::forTenant($user->tenant_id);
        if (! $connection?->isConnected()) {
            return response()->json(['message' => 'Conectá tu cuenta de Mercado Libre primero.'], 422);
        }

        if (! $connection->sync_enabled) {
            return response()->json(['message' => 'Activá la sincronización automática para publicar el catálogo.'], 422);
        }

        $this->sync->dispatchAll($connection);

        return response()->json(['data' => $this->transform($connection->fresh())], 202);
    }

    /**
     * Guarda la cuenta conectada. Si es otra cuenta que la anterior, sus
     * publicaciones no le pertenecen: se olvidan (y las categorías, si además
     * es de otro país).
     *
     * @param  array{access_token: string, refresh_token: string, expires_in: int}  $token
     * @param  array<mixed>  $account
     * @param  array<mixed>  $site
     */
    private function storeConnection(int $tenantId, int $userId, array $token, array $account, array $site): MercadoLibreConnection
    {
        return DB::transaction(function () use ($tenantId, $userId, $token, $account, $site): MercadoLibreConnection {
            $connection = MercadoLibreConnection::forTenant($tenantId) ?? new MercadoLibreConnection(['tenant_id' => $tenantId]);
            $siteId = (string) $account['site_id'];

            if ($connection->exists && $connection->ml_user_id !== (int) $account['id']) {
                MercadoLibreListing::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();

                if ($connection->site_id !== $siteId) {
                    MercadoLibreProduct::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
                }
            }

            $connection->fill([
                'connected_by' => $userId,
                'status' => MercadoLibreConnection::STATUS_CONNECTED,
                'ml_user_id' => (int) $account['id'],
                'nickname' => $account['nickname'] ?? null,
                'site_id' => $siteId,
                'currency_id' => (string) ($site['default_currency_id'] ?? ''),
                'user_products' => in_array('user_product_seller', (array) ($account['tags'] ?? []), true),
                'connected_at' => now(),
            ]);
            $connection->setTokens($token['access_token'], $token['refresh_token'], $token['expires_in']);
            $connection->save();

            return $connection;
        });
    }

    private function backToSettings(string $result): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        return redirect()->away("{$frontendUrl}/configuracion/integraciones?mercadolibre={$result}#mercadolibre");
    }

    private function authorizeManage(Request $request): User
    {
        $user = $request->user();
        if (! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(?MercadoLibreConnection $connection): array
    {
        return [
            'configured' => $this->oauth->isConfigured(),
            'status' => $connection?->status ?? MercadoLibreConnection::STATUS_DISCONNECTED,
            'nickname' => $connection?->nickname,
            'site_id' => $connection?->site_id,
            'currency_id' => $connection?->currency_id,
            'connected_at' => $connection?->connected_at?->toIso8601String(),
            'sync_enabled' => (bool) $connection?->sync_enabled,
            'listing_type_id' => $connection?->listing_type_id ?? 'gold_special',
            'condition' => $connection?->condition ?? 'new',
            'default_quantity' => $connection?->default_quantity ?? 1,
            'last_full_sync_at' => $connection?->last_full_sync_at?->toIso8601String(),
            'summary' => $this->summary($connection),
        ];
    }

    /**
     * @return array{synced: int, pending: int, paused: int, needs_attention: int}
     */
    private function summary(?MercadoLibreConnection $connection): array
    {
        $counts = $connection === null ? collect() : MercadoLibreListing::withoutGlobalScopes()
            ->where('tenant_id', $connection->tenant_id)
            ->groupBy('status')
            ->selectRaw('status, count(*) as total')
            ->pluck('total', 'status');

        return [
            'synced' => (int) ($counts[MercadoLibreListing::STATUS_SYNCED] ?? 0),
            'pending' => (int) ($counts[MercadoLibreListing::STATUS_PENDING] ?? 0),
            'paused' => (int) ($counts[MercadoLibreListing::STATUS_PAUSED] ?? 0),
            'needs_attention' => (int) collect(MercadoLibreListing::NEEDS_ATTENTION)->sum(fn (string $status) => $counts[$status] ?? 0),
        ];
    }
}
