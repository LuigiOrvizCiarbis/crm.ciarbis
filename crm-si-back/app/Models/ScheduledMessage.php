<?php

namespace App\Models;

use App\Enums\ScheduledMessageStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTimezoneAwareDates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Mensaje escrito en el chat para enviarse más tarde. El archivo adjunto (si
 * hay) se guarda en disco al programar; el envío real lo hace
 * SendScheduledMessageJob con los mismos servicios que el envío inmediato.
 */
class ScheduledMessage extends Model
{
    use BelongsToTenant;
    use HasTimezoneAwareDates;
    use Prunable;

    protected $fillable = [
        'tenant_id', 'conversation_id', 'user_id', 'message_type', 'content',
        'media_path', 'media_mime', 'voice', 'scheduled_for', 'status',
        'queued_at', 'sent_at', 'message_id', 'error_reason', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ScheduledMessageStatus::class,
            'voice' => 'boolean',
            'scheduled_for' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ScheduledMessageStatus::pending());
    }

    public function deleteMedia(): void
    {
        if ($this->media_path !== null) {
            Storage::disk('public')->delete($this->media_path);
        }
    }

    public function prunable(): Builder
    {
        // updated_at es timestamptz: comparar en UTC. Ver HasTimezoneAwareDates.
        return static::withoutGlobalScopes()
            ->whereIn('status', [ScheduledMessageStatus::Sent, ScheduledMessageStatus::Cancelled, ScheduledMessageStatus::Failed])
            ->where('updated_at', '<=', now()->utc()->subDays(30));
    }

    protected function pruning(): void
    {
        // Enviados: el archivo ya lo referencia el Message creado, no se borra.
        if ($this->status !== ScheduledMessageStatus::Sent) {
            $this->deleteMedia();
        }
    }
}
