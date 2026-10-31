<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageInteraction extends Model
{
    protected $fillable = [
        'broadcast_recipient_id', 'target_message_id', 'source_message_id', 'contact_id',
        'type', 'value', 'content', 'payload', 'deduplication_key', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(BroadcastRecipient::class, 'broadcast_recipient_id');
    }

    public function targetMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'target_message_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
