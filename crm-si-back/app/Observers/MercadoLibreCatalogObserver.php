<?php

namespace App\Observers;

use App\Jobs\SyncMercadoLibreProductJob;
use App\Models\MercadoLibreConnection;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Encola el sync con Mercado Libre cuando cambia algo que se publica de un
 * producto o de una de sus variantes. El sync diario cubre lo que se escribe
 * sin eventos (updates masivos por query).
 */
class MercadoLibreCatalogObserver
{
    public bool $afterCommit = true;

    private const PRODUCT_FIELDS = ['name', 'price', 'description', 'is_active', 'custom_data'];

    private const VARIANT_FIELDS = ['sku', 'attribute_values', 'price', 'stock_quantity', 'stock_status', 'image_url'];

    // Un producto nuevo de WooCommerce se guarda antes que sus variantes: la
    // demora evita publicarlo como producto simple y cerrarlo segundos después.
    // Los cambios que llegan mientras tanto se suman al mismo job (único).
    private const DEBOUNCE_SECONDS = 15;

    public function saved(Product|ProductVariant $model): void
    {
        $fields = $model instanceof Product ? self::PRODUCT_FIELDS : self::VARIANT_FIELDS;

        if ($model->wasRecentlyCreated || $model->wasChanged($fields)) {
            $this->dispatch($model);
        }
    }

    public function deleted(Product|ProductVariant $model): void
    {
        $this->dispatch($model);
    }

    private function dispatch(Product|ProductVariant $model): void
    {
        if (! MercadoLibreConnection::forTenant($model->tenant_id)?->isSyncing()) {
            return;
        }

        SyncMercadoLibreProductJob::dispatch(
            $model->tenant_id,
            $model instanceof Product ? $model->id : $model->product_id,
        )->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
    }
}
