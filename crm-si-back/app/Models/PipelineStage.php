<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'pipeline_id',
        'name',
        'color',
        'sort_order',
        'is_default',
    ];

    protected static function booted(): void
    {
        static::creating(function (PipelineStage $stage): void {
            if (! $stage->pipeline_id && $stage->tenant_id) {
                $stage->pipeline_id = Pipeline::defaultFor($stage->tenant_id)->id;
            }
        });
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
