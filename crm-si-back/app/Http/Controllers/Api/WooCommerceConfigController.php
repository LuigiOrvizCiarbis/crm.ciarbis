<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WooCommerceConfig;
use App\Services\WooCommerceCatalogSync;
use App\Services\WooCommerceService;
use App\Services\WooCommerceSyncException;
use App\Services\WooCommerceUrlException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WooCommerceConfigController extends Controller
{
    public function __construct(
        private readonly WooCommerceService $woo,
        private readonly WooCommerceCatalogSync $catalogSync,
    ) {}

    /**
     * Config de WooCommerce del tenant. Nunca devuelve las credenciales: solo un
     * flag has_credentials para que la UI sepa si ya hay unas cargadas.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.view') && ! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        $config = WooCommerceConfig::where('tenant_id', $user->tenant_id)->first();

        return response()->json(['data' => $this->transform($config)]);
    }

    /**
     * Crea o actualiza la config. Las credenciales son write-only: si vienen no
     * vacías se encriptan; si vienen vacías o ausentes, se conservan las guardadas.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'store_url' => ['required', 'url', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'consumer_key' => ['nullable', 'string', 'max:255'],
            'consumer_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $config = WooCommerceConfig::firstOrNew(['tenant_id' => $user->tenant_id]);
        $config->tenant_id = $user->tenant_id;
        $config->store_url = rtrim($validated['store_url'], '/');
        $config->enabled = $validated['enabled'];

        if (! empty($validated['consumer_key'])) {
            $config->setEncryptedConsumerKey($validated['consumer_key']);
        }

        if (! empty($validated['consumer_secret'])) {
            $config->setEncryptedConsumerSecret($validated['consumer_secret']);
        }

        $config->save();

        return response()->json(['data' => $this->transform($config->fresh())]);
    }

    /**
     * Prueba la conexión con la tienda. Usa las credenciales nuevas del request si
     * el usuario las mandó; si no, las ya guardadas. Así el tenant detecta el
     * problema al configurar y no al primer intento de sync.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'store_url' => ['required', 'url', 'max:255'],
            'consumer_key' => ['nullable', 'string', 'max:255'],
            'consumer_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $config = WooCommerceConfig::where('tenant_id', $user->tenant_id)->first();

        $key = ! empty($validated['consumer_key'])
            ? $validated['consumer_key']
            : $config?->getDecryptedConsumerKey();

        $secret = ! empty($validated['consumer_secret'])
            ? $validated['consumer_secret']
            : $config?->getDecryptedConsumerSecret();

        if (! $key || ! $secret) {
            return response()->json([
                'data' => [
                    'ok' => false,
                    'error_code' => 'invalid_credentials',
                    'error_message' => 'No hay credenciales para probar.',
                ],
            ], 422);
        }

        $result = $this->woo->testConnection($validated['store_url'], $key, $secret);

        return response()->json(['data' => $result], $result['ok'] ? 200 : 422);
    }

    /**
     * Encola el sync del catálogo. Requiere config guardada con credenciales.
     * La UI sigue el progreso con show() (campo sync).
     */
    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        $config = WooCommerceConfig::where('tenant_id', $user->tenant_id)->first();

        if (! $config || ! $config->hasCredentials()) {
            return response()->json([
                'message' => 'Configurá y guardá las credenciales de WooCommerce antes de sincronizar.',
            ], 422);
        }

        try {
            // Falla temprano ante una URL bloqueada o credenciales ilegibles
            // (p. ej. tras rotar APP_KEY), antes de encolar.
            $this->woo->client($config);
        } catch (WooCommerceUrlException|WooCommerceSyncException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $this->catalogSync->start($config)) {
            return response()->json([
                'message' => 'Ya hay una sincronización en curso.',
                'data' => $this->transform($config->fresh()),
            ], 409);
        }

        return response()->json(['data' => $this->transform($config->fresh())], 202);
    }

    /**
     * Serializa la config sin exponer credenciales.
     *
     * @return array<string, mixed>
     */
    private function transform(?WooCommerceConfig $config): array
    {
        if (! $config) {
            return [
                'store_url' => null,
                'enabled' => false,
                'has_credentials' => false,
                'last_synced_at' => null,
                'sync' => null,
            ];
        }

        return [
            'store_url' => $config->store_url,
            'enabled' => $config->enabled,
            'has_credentials' => $config->hasCredentials(),
            'last_synced_at' => $config->last_synced_at?->toIso8601String(),
            'sync' => $config->sync_status === null ? null : [
                'status' => $config->sync_status,
                // En curso pero sin avance: la UI permite reiniciarla.
                'stale' => $this->catalogSync->isStale($config),
                'started_at' => $config->sync_started_at?->toIso8601String(),
                'finished_at' => $config->sync_finished_at?->toIso8601String(),
                'result' => $config->sync_result,
                'error' => $config->sync_error,
            ],
        ];
    }
}
