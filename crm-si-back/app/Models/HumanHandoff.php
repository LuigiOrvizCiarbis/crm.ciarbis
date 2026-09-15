<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HumanHandoff extends Model
{
    use BelongsToTenant;
    protected $fillable = [
        'tenant_id', 'conversation_id', 'channel_id', 'assigned_to', 'trigger_message_id',
        'status', 'reason', 'summary', 'customer_locale', 'acknowledged_at', 'resolved_at', 'cancelled_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function triggerMessage(): BelongsTo { return $this->belongsTo(Message::class, 'trigger_message_id'); }
    public function notifications(): HasMany { return $this->hasMany(HandoffNotificationAttempt::class); }

    public function isActive(): bool { return in_array($this->status, ['pending', 'acknowledged'], true); }
}
