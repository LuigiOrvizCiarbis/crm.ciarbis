<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cómo se publica un producto en Mercado Libre: si se publica, en qué
 * categoría y con qué atributos de la ficha técnica (marca, modelo…).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property bool $enabled
 * @property ?string $category_id
 * @property ?string $category_name
 * @property bool $category_predicted
 * @property list<array{id: string, value_id: ?string, value_name: string}> $attribute_values
 */
class MercadoLibreProduct extends Model
{
    use BelongsToTenant;

    protected $table = 'mercadolibre_products';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'enabled',
        'category_id',
        'category_name',
        'category_predicted',
        'attribute_values',
    ];

    protected $attributes = [
        'enabled' => true,
        'category_predicted' => false,
        'attribute_values' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'category_predicted' => 'boolean',
            'attribute_values' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
