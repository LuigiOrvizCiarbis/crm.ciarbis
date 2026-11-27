<?php

namespace App\Http\Resources;

use App\Models\MercadoLibreListing;
use App\Services\MercadoLibre\MercadoLibreItemBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MercadoLibreListing
 */
class MercadoLibreListingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name ?? '',
            'variant_id' => $this->product_variant_id,
            'variant_label' => MercadoLibreItemBuilder::variantLabel($this->variant),
            'status' => $this->status,
            'ml_item_id' => $this->ml_item_id,
            'ml_status' => $this->ml_status,
            'permalink' => $this->permalink,
            'error' => $this->error,
            'missing' => $this->missing ?? [],
            'synced_at' => $this->synced_at?->toIso8601String(),
        ];
    }
}
