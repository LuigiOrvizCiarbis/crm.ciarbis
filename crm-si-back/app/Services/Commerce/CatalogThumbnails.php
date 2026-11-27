<?php

namespace App\Services\Commerce;

use App\Models\Message;
use App\Models\Product;
use App\Models\WooCommerceConfig;
use App\Services\WooCommerceCatalogWriter;

/**
 * Portada de un mensaje o plantilla de catálogo. Sin portada, Meta usa el
 * primer producto del catálogo y rechaza el envío entero si ese producto no
 * está habilitado para WhatsApp; por eso se elige uno que funcione y, si Meta
 * lo rechaza, quien envía lo marca y prueba con el siguiente.
 */
class CatalogThumbnails
{
    /** Productos a probar como portada antes de darse por vencido. */
    private const MAX_CANDIDATES = 5;

    public function __construct(
        private readonly WhatsAppCatalogAvailability $availability,
        private readonly CatalogRetailerIds $retailerIds,
    ) {}

    /**
     * Primero los que seguro existen en WhatsApp: el último que Meta aceptó,
     * los que llegaron en carritos de clientes y los que se mandaron como
     * fichas. Después, productos simples sin "Sin stock", los más nuevos
     * primero. Sin los que Meta ya rechazó.
     *
     * @return list<string>
     */
    public function candidates(int $tenantId): array
    {
        $config = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $local = $config === null ? [] : Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', WooCommerceCatalogWriter::SOURCE)
            ->where('is_active', true)
            ->whereDoesntHave('variants', fn ($query) => $query->withoutGlobalScopes())
            ->where(fn ($query) => $query->whereNull('custom_data->stock_status')->orWhere('custom_data->stock_status', '!=', 'Sin stock'))
            ->latest('id')
            ->limit(self::MAX_CANDIDATES * 2)
            ->get()
            ->map(fn (Product $product) => $this->retailerIds->forProduct($product, null, $config))
            ->filter()
            ->all();

        $ids = array_values(array_unique(array_filter(
            [$this->availability->workingProduct($tenantId), ...$this->retailerIdsSeenInWhatsApp($tenantId), ...$local],
            fn ($id) => is_string($id) && $id !== '',
        )));

        return array_slice($this->availability->withoutMissingProducts($tenantId, $ids), 0, self::MAX_CANDIDATES);
    }

    /** Meta aceptó la portada: se usa primero la próxima vez. */
    public function accepted(int $tenantId, string $retailerId): void
    {
        $this->availability->rememberWorkingProduct($tenantId, $retailerId);
    }

    /** Meta rechazó la portada: el producto no está habilitado en WhatsApp. */
    public function rejected(int $tenantId, string $retailerId): void
    {
        $this->availability->markProductMissing($tenantId, $retailerId);
    }

    /**
     * Retailer ids que aparecieron en carritos recibidos o en fichas enviadas:
     * WhatsApp los tenía habilitados.
     *
     * @return list<string>
     */
    private function retailerIdsSeenInWhatsApp(int $tenantId): array
    {
        return Message::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('interactive->kind', ['order', 'products'])
            ->where('created_at', '>', now()->subDays(30))
            ->latest('id')
            ->limit(20)
            ->pluck('interactive')
            ->flatMap(fn ($interactive) => ($interactive['kind'] ?? null) === 'order'
                ? array_column((array) ($interactive['product_items'] ?? []), 'product_retailer_id')
                : (array) ($interactive['retailer_ids'] ?? []))
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }
}
