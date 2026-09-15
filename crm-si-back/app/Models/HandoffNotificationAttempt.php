<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffNotificationAttempt extends Model
{
    protected $fillable = [
        'human_handoff_id', 'destination_type', 'phone', 'status', 'external_id', 'error',
        'sent_at', 'delivered_at', 'read_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime',
    ];

    public function handoff(): BelongsTo { return $this->belongsTo(HumanHandoff::class, 'human_handoff_id'); }
}
