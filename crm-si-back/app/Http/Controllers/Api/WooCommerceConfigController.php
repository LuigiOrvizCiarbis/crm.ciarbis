<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\TemplateStatus;
use App\Models\MercadoPagoConfig;
use App\Models\WhatsAppTemplate;
use App\Models\WooCommerceConfig;
use App\Services\Commerce\WhatsAppCheckoutInstaller;
use App\Services\Commerce\WooCommerceWebhookRegistrar;
use App\Services\Templates\TemplateParameterRenderer;
use App\Services\WooCommerceCatalogSync;
use App\Services\WooCommerceService;
use App\Services\WooCommerceSyncException;
use App\Services\WooCommerceUrlException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WooCommerceConfigController extends Controller
{
    public function __construct(
        private readonly WooCommerceService $woo,
        private readonly WooCommerceCatalogSync $catalogSync,
        private readonly TemplateParameterRenderer $templateRenderer,
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

        return response()->json(['data' => $this->transform($config, $user->tenant_id)]);
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
            'whatsapp_checkout_enabled' => ['sometimes', 'boolean'],
            'meta_catalog_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'catalog_retailer_id_format' => ['sometimes', Rule::in([WooCommerceConfig::RETAILER_ID_PRODUCT_ID, WooCommerceConfig::RETAILER_ID_SKU])],
            'tracking_meta_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'paid_template_id' => ['sometimes', 'nullable', 'integer'],
            'shipped_template_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $this->assertCheckoutTemplate($validated, 'paid_template_id', $user->tenant_id, ['pedido', 'total'], 'pago confirmado');
        $this->assertCheckoutTemplate($validated, 'shipped_template_id', $user->tenant_id, ['pedido', 'seguimiento'], 'pedido despachado');

        $config = WooCommerceConfig::firstOrNew(['tenant_id' => $user->tenant_id]);
        $config->tenant_id = $user->tenant_id;
        $config->store_url = rtrim($validated['store_url'], '/');
        $config->enabled = $validated['enabled'];

        foreach (['whatsapp_checkout_enabled', 'catalog_retailer_id_format', 'paid_template_id', 'shipped_template_id'] as $field) {
            if (array_key_exists($field, $validated)) {
                $config->{$field} = $validated[$field];
            }
        }
        foreach (['meta_catalog_id', 'tracking_meta_key'] as $field) {
            if (array_key_exists($field, $validated)) {
                $value = trim((string) $validated[$field]);
                $config->{$field} = $value === '' ? null : $value;
            }
        }

        if (! empty($validated['consumer_key'])) {
            $config->setEncryptedConsumerKey($validated['consumer_key']);
        }

        if (! empty($validated['consumer_secret'])) {
            $config->setEncryptedConsumerSecret($validated['consumer_secret']);
        }

        $config->save();

        return response()->json(['data' => $this->transform($config->fresh(), $user->tenant_id)]);
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
                'data' => $this->transform($config->fresh(), $user->tenant_id),
            ], 409);
        }

        return response()->json(['data' => $this->transform($config->fresh(), $user->tenant_id)], 202);
    }

    /**
     * Registra (o re-registra) los webhooks de producto y pedido en la tienda.
     */
    public function webhooks(Request $request, WooCommerceWebhookRegistrar $registrar): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('woocommerce.manage')) {
            abort(403);
        }

        $config = WooCommerceConfig::where('tenant_id', $user->tenant_id)->first();

        if (! $config || ! $config->hasCredentials()) {
            return response()->json(['message' => 'Configurá y guardá las credenciales de WooCommerce primero.'], 422);
        }

        try {
            $registrar->register($config);
        } catch (WooCommerceUrlException|WooCommerceSyncException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ConnectionException) {
            return response()->json(['message' => 'No pudimos comunicarnos con la tienda. Probá de nuevo en un rato.'], 422);
        }

        return response()->json(['data' => $this->transform($config->fresh(), $user->tenant_id)]);
    }

    /**
     * La plantilla tiene que ser del tenant, estar aprobada y aceptar las
     * variables literales del aviso (p. ej. {{pedido}} y {{total}}).
     *
     * @param  array<string, mixed>  $validated
     * @param  list<string>  $variables
     *
     * @throws ValidationException
     */
    private function assertCheckoutTemplate(array $validated, string $field, int $tenantId, array $variables, string $label): void
    {
        if (empty($validated[$field])) {
            return;
        }

        $template = WhatsAppTemplate::where('tenant_id', $tenantId)->find($validated[$field]);
        if (! $template || $template->status !== TemplateStatus::Approved) {
            throw ValidationException::withMessages([$field => "La plantilla de {$label} ya no está aprobada. Elegí otra."]);
        }

        $parameters = array_map(
            fn (string $name) => ['component' => 'body', 'name' => $name, 'source' => 'literal', 'value' => $name],
            $variables,
        );

        $errors = $this->templateRenderer->validateAgainstTemplate($template, $parameters);
        if ($errors !== []) {
            $details = implode(' ', array_merge(...array_values($errors)));
            throw ValidationException::withMessages([$field => "Plantilla de {$label}: {$details}"]);
        }
    }

    /**
     * Códigos de lo que falta para vender por WhatsApp; la UI los traduce.
     *
     * @return list<'woo_credentials'|'mercado_pago'|'meta_catalog_id'|'flow_installed'>
     */
    private function checkoutMissing(?WooCommerceConfig $config, int $tenantId): array
    {
        $missing = [];

        if (! $config?->hasCredentials()) {
            $missing[] = 'woo_credentials';
        }

        $mercadoPago = MercadoPagoConfig::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        if (! $mercadoPago?->hasCredentials()) {
            $missing[] = 'mercado_pago';
        }

        if (empty($config?->meta_catalog_id)) {
            $missing[] = 'meta_catalog_id';
        }

        if (! app(WhatsAppCheckoutInstaller::class)->hasPublishedFlow($tenantId)) {
            $missing[] = 'flow_installed';
        }

        return $missing;
    }

    /**
     * Serializa la config sin exponer credenciales.
     *
     * @return array<string, mixed>
     */
    private function transform(?WooCommerceConfig $config, int $tenantId): array
    {
        if (! $config) {
            return [
                'store_url' => null,
                'enabled' => false,
                'has_credentials' => false,
                'last_synced_at' => null,
                'sync' => null,
                'whatsapp_checkout_enabled' => false,
                'meta_catalog_id' => null,
                'catalog_retailer_id_format' => WooCommerceConfig::RETAILER_ID_PRODUCT_ID,
                'tracking_meta_key' => null,
                'paid_template_id' => null,
                'shipped_template_id' => null,
                'webhooks_active' => false,
                'checkout_missing' => $this->checkoutMissing(null, $tenantId),
            ];
        }

        return [
            'store_url' => $config->store_url,
            'enabled' => $config->enabled,
            'has_credentials' => $config->hasCredentials(),
            'last_synced_at' => $config->last_synced_at?->toIso8601String(),
            'whatsapp_checkout_enabled' => $config->whatsapp_checkout_enabled,
            'meta_catalog_id' => $config->meta_catalog_id,
            'catalog_retailer_id_format' => $config->catalog_retailer_id_format ?? WooCommerceConfig::RETAILER_ID_PRODUCT_ID,
            'tracking_meta_key' => $config->tracking_meta_key,
            'paid_template_id' => $config->paid_template_id,
            'shipped_template_id' => $config->shipped_template_id,
            'webhooks_active' => ! empty($config->webhook_ids),
            'checkout_missing' => $this->checkoutMissing($config, $tenantId),
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
