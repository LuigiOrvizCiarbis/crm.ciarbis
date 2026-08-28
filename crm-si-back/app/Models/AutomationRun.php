<?php

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    use BelongsToTenant;
    use HasTimezoneAwareDates;
    use Prunable;

    protected $fillable = ['tenant_id', 'automation_rule_id', 'rule_version', 'status', 'subject_type', 'subject_id', 'event_id', 'recurrence_number', 'deduplication_key', 'context', 'result', 'error', 'scheduled_for', 'queued_at', 'started_at', 'finished_at', 'attempts'];

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'context' => 'array',
            'result' => 'array',
            'scheduled_for' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function actionRuns(): HasMany
    {
        return $this->hasMany(AutomationActionRun::class)->orderBy('position');
    }

    public function prunable()
    {
        // created_at es timestamptz: comparar en UTC, no en hora local. Ver
        // HasTimezoneAwareDates.
        return static::where('created_at', '<=', now()->utc()->subDays(90));
    }
}
