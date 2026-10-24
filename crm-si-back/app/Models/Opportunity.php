<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranch;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Opportunity extends Model
{
    use BelongsToTenant;
    use HasBranch;
    use HasTags;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'pipeline_id',
        'contact_id',
        'conversation_id',
        'pipeline_stage_id',
        'assigned_to',
        'title',
        'status',
        'source_type',
        'value',
        'notes',
        'last_activity_at',
    ];

    protected static function booted(): void
    {
        // La etapa manda: una oportunidad siempre vive en el pipeline de su etapa.
        $syncPipeline = function (Opportunity $opportunity): void {
            if ($opportunity->pipeline_stage_id && ($opportunity->isDirty('pipeline_stage_id') || ! $opportunity->pipeline_id)) {
                $stagePipelineId = PipelineStage::withoutGlobalScopes()->whereKey($opportunity->pipeline_stage_id)->value('pipeline_id');
                if ($stagePipelineId) {
                    $opportunity->pipeline_id = $stagePipelineId;
                }
            }

            if (! $opportunity->pipeline_id && $opportunity->tenant_id) {
                $opportunity->pipeline_id = Pipeline::defaultFor($opportunity->tenant_id)->id;
            }
        };

        // creating (no saving): corre después de que BelongsToTenant asigna tenant_id.
        static::creating($syncPipeline);
        static::updating($syncPipeline);

        // La etapa del chat refleja la de su oportunidad en el pipeline
        // predeterminado (con varias, la última movida). Se actualiza vía
        // Eloquent para que dispare conversation.stage_changed.
        static::saved(function (Opportunity $opportunity): void {
            if (! $opportunity->conversation_id || ! $opportunity->pipeline_stage_id) {
                return;
            }
            if (! $opportunity->wasRecentlyCreated && ! $opportunity->wasChanged(['pipeline_stage_id', 'pipeline_id', 'conversation_id'])) {
                return;
            }
            if (! Pipeline::withoutGlobalScopes()->whereKey($opportunity->pipeline_id)->value('is_default')) {
                return;
            }

            $conversation = Conversation::withoutGlobalScopes()->find($opportunity->conversation_id);
            if ($conversation && (int) $conversation->pipeline_stage_id !== (int) $opportunity->pipeline_stage_id) {
                $conversation->update(['pipeline_stage_id' => $opportunity->pipeline_stage_id]);
            }
        });
    }

    /**
     * Oportunidades que mandan sobre la etapa del chat: las del pipeline predeterminado.
     */
    public function scopeInDefaultPipeline(Builder $query): Builder
    {
        return $query->whereHas('pipeline', fn (Builder $pipeline) => $pipeline->where('is_default', true));
    }

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'last_activity_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'pipeline_stage_id');
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('opportunities.view_any')) {
            return $query;
        }

        if (! $user->can('opportunities.view_assigned')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('assigned_to', $user->id);
    }
}
