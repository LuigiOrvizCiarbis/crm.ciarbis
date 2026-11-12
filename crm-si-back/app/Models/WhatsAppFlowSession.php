<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppFlowSession extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    protected $table = 'whatsapp_flow_sessions';

    protected $fillable = [
        'tenant_id',
        'whatsapp_flow_id',
        'conversation_id',
        'contact_id',
        'message_id',
        'flow_token',
        'status',
        'data',
        'response',
        'completed_at',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'response' => 'array',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(WhatsAppFlow::class, 'whatsapp_flow_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }
}
