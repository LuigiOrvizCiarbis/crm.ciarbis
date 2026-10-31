<?php

namespace App\Models;

use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationAction extends Model
{
    use HasTimezoneAwareDates;

    protected $fillable = ['automation_rule_id', 'position', 'type', 'config'];

    protected $casts = ['config' => 'array'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }
}
