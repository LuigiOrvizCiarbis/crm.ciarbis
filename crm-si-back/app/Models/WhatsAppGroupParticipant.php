<?php

namespace App\Models;

use App\Enums\WhatsAppGroupParticipantStatus;
use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppGroupParticipant extends Model
{
    use HasTimezoneAwareDates;

    // Str::snake('WhatsAppGroupParticipant') separa "Whats"+"App", no coincide
    // con el nombre de la migración. Ver WhatsAppGroup::$table.
    protected $table = 'whatsapp_group_participants';

    protected $fillable = [
        'whatsapp_group_id',
        'contact_id',
        'wa_id',
        'user_id_bsuid',
        'parent_user_id',
        'username',
        'display_name',
        'role',
        'status',
        'join_request_id',
        'joined_at',
        'removed_at',
        'removed_by',
        'invited_message_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => WhatsAppGroupParticipantStatus::class,
            'joined_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WhatsAppGroup::class, 'whatsapp_group_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function invitedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'invited_message_id');
    }
}
