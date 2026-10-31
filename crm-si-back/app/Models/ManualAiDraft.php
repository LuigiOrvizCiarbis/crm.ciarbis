<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualAiDraft extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'conversation_id', 'user_id', 'source_message_id',
        'status', 'content', 'error_code', 'version', 'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function sourceMessage(): BelongsTo { return $this->belongsTo(Message::class, 'source_message_id'); }

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'ready'], true)
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function payload(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'source_message_id' => $this->source_message_id,
            'status' => $this->status,
            'content' => $this->content,
            'error_code' => $this->error_code,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
