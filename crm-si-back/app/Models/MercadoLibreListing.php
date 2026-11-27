<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ítem de Mercado Libre de una unidad vendible: el producto (sin variantes) o
 * una de sus variantes. Guarda el estado del último sync.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property ?int $product_variant_id
 * @property ?string $ml_item_id
 * @property ?string $ml_user_product_id
 * @property ?string $ml_status
 * @property ?string $permalink
 * @property string $status
 * @property ?string $error
 * @property ?list<array{id: string, name: string}> $missing
 * @property ?string $payload_hash
 * @property ?Carbon $synced_at
 */
class MercadoLibreListing extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ERROR = 'error';

    public const STATUS_NEEDS_ATTRIBUTES = 'needs_attributes';

    /** Estados que requieren que alguien complete o corrija algo. */
    public const NEEDS_ATTENTION = [self::STATUS_ERROR, self::STATUS_NEEDS_ATTRIBUTES];

    protected $table = 'mercadolibre_listings';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'product_variant_id',
        'ml_item_id',
        'ml_user_product_id',
        'ml_status',
        'permalink',
        'status',
        'error',
        'missing',
        'payload_hash',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'missing' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
