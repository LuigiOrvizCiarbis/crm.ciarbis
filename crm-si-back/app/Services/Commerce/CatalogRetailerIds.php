<?php

namespace App\Services\Commerce;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WooCommerceConfig;
use App\Services\WooCommerceCatalogWriter;

/**
 * Traduce entre los productos locales (sincronizados de WooCommerce) y el
 * retailer id del catálogo de Meta, según el formato configurado en la tienda.
 */
class CatalogRetailerIds
{
    public function forProduct(Product $product, ?ProductVariant $variant, WooCommerceConfig $config): ?string
    {
        if ($config->catalog_retailer_id_format === WooCommerceConfig::RETAILER_ID_SKU) {
            $sku = $variant?->sku ?? ($product->custom_data['sku'] ?? null);

            return filled($sku) ? (string) $sku : null;
        }

        $id = $variant?->external_id ?? $product->external_id;

        return filled($id) ? (string) $id : null;
    }

    /**
     * Busca primero por id de WooCommerce (también los formatos viejos
     * `wc_post_id_123` / `sku_123`) y después por SKU exacto.
     *
     * @return array{product: Product, variant: ?ProductVariant}|null
     */
    public function resolve(int $tenantId, string $retailerId, WooCommerceConfig $config): ?array
    {
        $retailerId = trim($retailerId);

        if ($retailerId === '') {
            return null;
        }

        $externalId = preg_match('/^\d+$/', $retailerId) ? $retailerId
            : (preg_match('/_(\d+)$/', $retailerId, $match) ? $match[1] : null);

        if ($externalId !== null) {
            $found = $this->byVariant($tenantId, 'external_id', $externalId)
                ?? $this->byProduct($tenantId, fn ($query) => $query->where('external_id', $externalId));

            if ($found !== null) {
                return $found;
            }
        }

        return $this->byVariant($tenantId, 'sku', $retailerId)
            ?? $this->byProduct($tenantId, fn ($query) => $query->where('custom_data->sku', $retailerId));
    }

    /** @return array{product: Product, variant: ProductVariant}|null */
    private function byVariant(int $tenantId, string $column, string $value): ?array
    {
        $variant = ProductVariant::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where($column, $value)
            ->whereHas('product', fn ($query) => $query->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('source', WooCommerceCatalogWriter::SOURCE))
            ->first();

        if ($variant === null) {
            return null;
        }

        $product = Product::withoutGlobalScopes()->find($variant->product_id);

        return $product ? ['product' => $product, 'variant' => $variant] : null;
    }

    /** @return array{product: Product, variant: null}|null */
    private function byProduct(int $tenantId, \Closure $filter): ?array
    {
        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', WooCommerceCatalogWriter::SOURCE)
            ->where($filter)
            ->first();

        return $product ? ['product' => $product, 'variant' => null] : null;
    }
}
