<?php

namespace App\Models;

use App\Enums\WhatsAppGroupStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranch;
use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WhatsAppGroup extends Model
{
    use BelongsToTenant;
    use HasBranch;
    use HasTimezoneAwareDates;

    // Str::snake('WhatsAppGroup') da 'whats_app_group' (separa "Whats"+"App"),
    // no 'whatsapp_group'. Explícito para que coincida con la migración.
    protected $table = 'whatsapp_groups';

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'channel_id',
        'conversation_id',
        'created_by',
        'opportunity_id',
        'request_id',
        'group_id',
        'subject',
        'description',
        'join_approval_mode',
        'invite_link',
        'status',
        'suspended',
        'total_participant_count',
        'profile_picture_url',
        'creation_timestamp',
        'last_synced_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => WhatsAppGroupStatus::class,
            'suspended' => 'boolean',
            'total_participant_count' => 'integer',
            'creation_timestamp' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(WhatsAppGroupParticipant::class);
    }

    public function activeParticipants(): HasMany
    {
        return $this->participants()->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === WhatsAppGroupStatus::Active;
    }
}
