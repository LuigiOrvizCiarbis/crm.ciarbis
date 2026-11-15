<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de la Conversions API for Business Messaging enviado (o a enviar) a
 * Meta por una oportunidad. Una fila por oportunidad y evento.
 */
class MetaConversionEvent extends Model
{
    use BelongsToTenant;

    public const PURCHASE = 'Purchase';

    public const LEAD_SUBMITTED = 'LeadSubmitted';

    protected $fillable = [
        'tenant_id',
        'opportunity_id',
        'ad_referral_id',
        'event_name',
        'messaging_channel',
        'status',
        'attempts',
        'payload',
        'response',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'sent_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function adReferral(): BelongsTo
    {
        return $this->belongsTo(AdReferral::class);
    }
}
