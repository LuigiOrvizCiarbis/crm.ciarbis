<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\WooCommerceCatalogWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => $this->price,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'external_type' => $this->external_type,
            'requires_shipping' => $this->requires_shipping,
            'custom_data' => $this->custom_data ?? [],
            // Campos que pisa el sync: la UI los muestra bloqueados.
            'managed_fields' => $this->source === WooCommerceCatalogWriter::SOURCE
                ? WooCommerceCatalogWriter::managedKeys($this->tenant_id)
                : [],
            'variants_count' => $this->whenCounted('variants'),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn (ProductVariant $variant): array => [
                'id' => $variant->id,
                'external_id' => $variant->external_id,
                'sku' => $variant->sku,
                'attributes' => $variant->attribute_values,
                'price' => $variant->price,
                'stock_quantity' => $variant->stock_quantity,
                'stock_status' => $variant->stock_status,
                'image_url' => $variant->image_url,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
