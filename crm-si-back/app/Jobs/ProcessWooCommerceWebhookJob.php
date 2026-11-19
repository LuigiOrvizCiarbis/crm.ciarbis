<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\WhatsAppCheckout;
use App\Models\WooCommerceConfig;
use App\Services\Commerce\WhatsAppCheckoutLifecycle;
use App\Services\WooCommerceCatalogWriter;
use App\Services\WooCommerceService;
use App\Services\WooCommerceSyncException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * Aplica un webhook de WooCommerce: refresca el producto en el catálogo local
 * o actualiza el pedido de WhatsApp asociado. El producto se vuelve a leer de
 * la REST API: el payload sólo indica qué cambió.
 */
class ProcessWooCommerceWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public int $configId, public string $topic, public array $payload) {}

    public function handle(WooCommerceService $woo, WooCommerceCatalogWriter $writer, WhatsAppCheckoutLifecycle $lifecycle): void
    {
        $config = WooCommerceConfig::withoutGlobalScopes()->find($this->configId);
        if (! $config || ! $config->hasCredentials()) {
            return;
        }

        switch ($this->topic) {
            case 'product.created':
            case 'product.updated':
            case 'product.restored':
                // Una variación llega con el id del padre en parent_id.
                $id = (int) (($this->payload['parent_id'] ?? 0) ?: ($this->payload['id'] ?? 0));
                if ($id > 0) {
                    $this->refreshProduct($woo, $writer, $config, $woo->client($config), $id);
                }
                break;

            case 'product.deleted':
                if (isset($this->payload['id'])) {
                    $this->deactivate($config, (int) $this->payload['id']);
                }
                break;

            case 'order.updated':
                $this->orderUpdated($woo, $writer, $lifecycle, $config);
                break;
        }
    }

    private function orderUpdated(WooCommerceService $woo, WooCommerceCatalogWriter $writer, WhatsAppCheckoutLifecycle $lifecycle, WooCommerceConfig $config): void
    {
        // Primero el pedido: un error al refrescar productos no debe demorar el
        // aviso de pago o despacho. orderUpdated es idempotente ante reintentos.
        $checkout = $this->findCheckout($config);
        if ($checkout !== null) {
            $lifecycle->orderUpdated($checkout, $this->payload);
        }

        // Los cambios de stock por ventas no disparan webhooks de producto.
        $productIds = collect($this->payload['line_items'] ?? [])
            ->map(fn ($line) => (int) (is_array($line) ? ($line['product_id'] ?? 0) : 0))
            ->filter()
            ->unique()
            ->values();

        if ($productIds->isNotEmpty()) {
            $client = $woo->client($config);
            foreach ($productIds as $productId) {
                $this->refreshProduct($woo, $writer, $config, $client, $productId);
            }
        }
    }

    private function findCheckout(WooCommerceConfig $config): ?WhatsAppCheckout
    {
        $query = fn () => WhatsAppCheckout::withoutGlobalScopes()->where('tenant_id', $config->tenant_id);

        foreach ($this->payload['meta_data'] ?? [] as $meta) {
            if (is_array($meta) && ($meta['key'] ?? null) === 'crm_checkout_id' && is_numeric($meta['value'] ?? null)) {
                $checkout = $query()->find((int) $meta['value']);
                if ($checkout !== null) {
                    return $checkout;
                }
            }
        }

        if (! isset($this->payload['id'])) {
            return null;
        }

        return $query()->where('external_order_id', (int) $this->payload['id'])->latest('id')->first();
    }

    /**
     * @throws WooCommerceSyncException
     */
    private function refreshProduct(WooCommerceService $woo, WooCommerceCatalogWriter $writer, WooCommerceConfig $config, PendingRequest $client, int $id): void
    {
        $response = $client->get("/products/{$id}");

        if ($response->status() === 404) {
            $this->deactivate($config, $id);

            return;
        }

        $item = $response->json();
        if (! $response->successful() || ! is_array($item) || ! isset($item['id'])) {
            throw new WooCommerceSyncException("WooCommerce respondió con un error ({$response->status()}) al leer el producto {$id}.");
        }

        if (($item['status'] ?? null) !== 'publish') {
            $this->deactivate($config, $id);

            return;
        }

        $variations = ($item['type'] ?? null) === 'variable' ? $woo->fetchVariations($client, $id) : [];

        $local = $this->localProduct($config, $id);

        // Con un sync completo en curso se usa su corrida: si no, el cierre de
        // ese sync desactivaría este producto por no haberlo visto.
        $runId = $config->sync_status === WooCommerceConfig::SYNC_RUNNING && $config->sync_run_id !== null
            ? $config->sync_run_id
            : ($local?->external_sync_run_id ?? (string) Str::uuid());

        $writer->upsertSingle($config->tenant_id, $item, $variations, $runId);
    }

    private function deactivate(WooCommerceConfig $config, int $id): void
    {
        $this->localProduct($config, $id)?->update(['is_active' => false]);
    }

    private function localProduct(WooCommerceConfig $config, int $id): ?Product
    {
        return Product::withoutGlobalScopes()
            ->where('tenant_id', $config->tenant_id)
            ->where('source', WooCommerceCatalogWriter::SOURCE)
            ->where('external_id', (string) $id)
            ->first();
    }
}
