<?php

namespace App\Models;

use App\Enums\BroadcastRecipientStatus;
use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BroadcastRecipient extends Model
{
    use HasTimezoneAwareDates;

    protected $fillable = [
        'broadcast_campaign_id',
        'conversation_id',
        'contact_id',
        'phone_normalized',
        'message_id',
        'status',
        'error',
        'failure_code',
        'failure_details',
        'queued_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BroadcastRecipientStatus::class,
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failure_details' => 'array',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(BroadcastCampaign::class, 'broadcast_campaign_id');
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

    public function interactions(): HasMany
    {
        return $this->hasMany(MessageInteraction::class);
    }
}
