<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada de un contacto originada en un anuncio de Meta (CTWA / CTM / CTD).
 */
class AdReferral extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'channel_id', 'contact_id', 'conversation_id', 'message_id',
        'platform', 'source_type', 'ad_id', 'ref', 'headline', 'body', 'source_url',
        'media_type', 'media_url', 'post_id', 'ctwa_clid', 'raw', 'dedupe_key', 'referred_at',
    ];

    protected $casts = [
        'raw' => 'array',
        'referred_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
