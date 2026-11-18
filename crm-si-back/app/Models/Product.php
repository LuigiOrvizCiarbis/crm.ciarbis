<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToTenant;

    // Límites de la API de productos; las integraciones recortan a lo mismo
    // para que un producto sincronizado se pueda reenviar sin cambios.
    public const NAME_MAX_LENGTH = 150;

    public const DESCRIPTION_MAX_LENGTH = 5000;

    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'price',
        'description',
        'is_active',
        'source',
        'external_id',
        'external_type',
        'requires_shipping',
        'external_sync_run_id',
        'custom_data',
    ];

    protected $attributes = [
        'custom_data' => '{}',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'requires_shipping' => 'boolean',
        'custom_data' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    public function scopeWhereCustomField(Builder $query, string $key, mixed $value): Builder
    {
        if (is_array($value)) {
            return $query->whereJsonContains("custom_data->{$key}", $value);
        }

        if ($value === null) {
            return $query->whereRaw('custom_data ->> ? IS NULL', [$key]);
        }

        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $query->whereRaw('custom_data ->> ? = ?', [$key, $stringValue]);
    }
}
