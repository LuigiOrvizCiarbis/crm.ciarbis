<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /**
     * Pipeline predeterminado del tenant. Lo crea si falta, para que las
     * entradas automáticas siempre tengan a dónde caer.
     */
    public static function defaultFor(int $tenantId): self
    {
        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_default', true)
            ->first()
            ?? static::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => 'Ventas'],
                ['is_default' => ! static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()],
            );
    }

    /**
     * Etapa inicial: la marcada como predeterminada o, si ninguna lo está,
     * la primera según el orden.
     */
    public function entryStage(): ?PipelineStage
    {
        return $this->stages()->orderByDesc('is_default')->orderBy('sort_order')->first();
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStage::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
