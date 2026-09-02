<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstagramComment extends Model
{
    protected $fillable = [
        'tenant_id', 'channel_id', 'contact_id', 'conversation_id', 'assigned_to',
        'external_id', 'parent_external_id', 'author_external_id', 'author_username',
        'text', 'media_id', 'media_product_type', 'ad_id', 'ad_title', 'status',
        'visibility', 'commented_at', 'private_reply_deadline', 'private_replied_at',
        'private_reply_external_id', 'last_action_at',
    ];

    protected $casts = [
        'commented_at' => 'datetime',
        'private_reply_deadline' => 'datetime',
        'private_replied_at' => 'datetime',
        'last_action_at' => 'datetime',
    ];

    public function channel(): BelongsTo { return $this->belongsTo(Channel::class); }
    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function conversation(): BelongsTo { return $this->belongsTo(Conversation::class); }
    public function assignedUser(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('conversations.view_any')) return $query;
        return $query->where(function (Builder $q) use ($user) {
            $q->where('assigned_to', $user->id)
                ->orWhereHas('channel', fn (Builder $channel) => $channel->whereIn('id', $user->accessibleChannelIds()));
        });
    }

    public function privateReplyAvailable(): bool
    {
        return $this->private_replied_at === null
            && $this->private_reply_deadline !== null
            && $this->private_reply_deadline->isFuture();
    }
}
