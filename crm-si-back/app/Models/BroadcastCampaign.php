<?php

namespace App\Models;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BroadcastCampaign extends Model
{
    use BelongsToTenant;
    use HasTimezoneAwareDates;

    protected $fillable = [
        'tenant_id',
        'channel_id',
        'whatsapp_template_id',
        'created_by',
        'name',
        'status',
        'audience_filters',
        'components',
        'reply_tags',
        'audience_count',
        'duplicate_phone_count',
        'without_consent_count',
        'consent_warning_accepted_by',
        'consent_warning_accepted_at',
        'estimated_cost_usd',
        'actual_cost_usd',
        'interval_seconds',
        'scheduled_at',
        'started_at',
        'completed_at',
        'results_tracking_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => BroadcastStatus::class,
            'audience_filters' => 'array',
            'components' => 'array',
            'reply_tags' => 'array',
            'estimated_cost_usd' => 'decimal:2',
            'actual_cost_usd' => 'decimal:2',
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'consent_warning_accepted_at' => 'immutable_datetime',
            'results_tracking_version' => 'integer',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function consentWarningAcceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consent_warning_accepted_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    public function resultsEnabled(): bool
    {
        return (int) $this->results_tracking_version === 1;
    }

    /**
     * Payload que viaja en el botón de respuesta rápida y Meta devuelve tal
     * cual en el webhook: así la regla no depende del texto del botón.
     */
    public static function replyTagPayload(int $buttonIndex): string
    {
        return 'reply_tag:'.$buttonIndex;
    }

    /**
     * Componentes de envío que marcan cada botón con regla de etiqueta.
     *
     * @return list<array{type: string, sub_type: string, index: string, parameters: list<array{type: string, payload: string}>}>
     */
    public function replyTagButtonComponents(): array
    {
        return array_map(fn (array $rule): array => [
            'type' => 'button',
            'sub_type' => 'quick_reply',
            'index' => (string) $rule['button_index'],
            'parameters' => [['type' => 'payload', 'payload' => self::replyTagPayload((int) $rule['button_index'])]],
        ], $this->reply_tags ?? []);
    }

    public function replyTagIdFor(?string $payload): ?int
    {
        foreach ($this->reply_tags ?? [] as $rule) {
            if (self::replyTagPayload((int) $rule['button_index']) === $payload) {
                return (int) $rule['tag_id'];
            }
        }

        return null;
    }

    public function refreshDeliveryStatus(): void
    {
        $counts = $this->recipients()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pending = (int) ($counts[BroadcastRecipientStatus::Pending->value] ?? 0)
            + (int) ($counts[BroadcastRecipientStatus::Queued->value] ?? 0);

        if ($pending > 0) {
            return;
        }

        $sent = (int) ($counts[BroadcastRecipientStatus::Sent->value] ?? 0);
        $failed = (int) ($counts[BroadcastRecipientStatus::Failed->value] ?? 0);
        $status = match (true) {
            $failed === 0 => BroadcastStatus::Completed,
            $sent > 0 => BroadcastStatus::Partial,
            default => BroadcastStatus::Failed,
        };

        $this->update([
            'status' => $status,
            'actual_cost_usd' => round($sent * (float) config('broadcasts.cost_per_message_usd'), 2),
            'completed_at' => now(),
        ]);
    }
}
