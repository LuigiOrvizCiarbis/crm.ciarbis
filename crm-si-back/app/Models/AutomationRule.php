<?php

namespace App\Models;

use App\Enums\AutomationRuleStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends Model
{
    use BelongsToTenant;
    use HasTimezoneAwareDates;

    protected $attributes = [
        'version' => 1,
        'status' => 'draft',
        'trigger_config' => '{}',
    ];

    protected $fillable = ['tenant_id', 'created_by', 'name', 'status', 'version', 'trigger_type', 'trigger_config', 'conditions', 'timezone', 'activated_at'];

    protected function casts(): array
    {
        return [
            'status' => AutomationRuleStatus::class,
            'trigger_config' => 'array',
            'conditions' => 'array',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function actions(): HasMany
    {
        return $this->hasMany(AutomationAction::class)->orderBy('position');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
