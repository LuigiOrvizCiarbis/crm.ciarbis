<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variante de un producto sincronizado desde una tienda externa (WooCommerce
 * variation): una combinación de atributos con precio y stock propios.
 *
 * @property int $id
 * @property int $product_id
 * @property string $external_id
 * @property ?string $sku
 * @property array<string, string> $attribute_values
 * @property ?string $price
 * @property ?int $stock_quantity
 * @property ?string $stock_status
 * @property ?string $image_url
 */
class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'external_id',
        'sku',
        'attribute_values',
        'price',
        'stock_quantity',
        'stock_status',
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'attribute_values' => 'array',
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
