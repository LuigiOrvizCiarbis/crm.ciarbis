<?php

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationActionRun extends Model
{
    use HasTimezoneAwareDates;

    protected $fillable = [
        'automation_run_id',
        'automation_action_id',
        'position',
        'status',
        'attempts',
        'delivery_key',
        'delivery_started_at',
        'delivery_confirmed_at',
        'input',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'input' => 'array',
            'result' => 'array',
            'delivery_started_at' => 'immutable_datetime',
            'delivery_confirmed_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class, 'automation_run_id');
    }

    public function action(): BelongsTo
    {
        return $this->belongsTo(AutomationAction::class, 'automation_action_id');
    }
}
