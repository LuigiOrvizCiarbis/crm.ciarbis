<?php

namespace App\Models;

use App\Enums\SenderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class MessageReaction extends Model
{
    protected $fillable = [
        'tenant_id',
        'message_id',
        'conversation_id',
        'reactor_type',
        'reactor_id',
        'emoji',
        'external_id',
        'reacted_at',
    ];

    protected $casts = [
        'reactor_type' => SenderType::class,
        'reacted_at' => 'datetime',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Agregado por emoji listo para la burbuja. Usado tanto por el accessor
     * `Message::reaction_summary` (servido por HTTP, con auth() disponible)
     * como por el evento de broadcast (sin auth(), corre en cola/worker) —
     * por eso NO calcula `reacted_by_me` acá: sólo expone `reactor_user_ids`
     * y cada consumidor decide la pertenencia con el id que tenga a mano.
     *
     * @return list<array{emoji: string, count: int, reactor_user_ids: list<int>}>
     */
    public static function summaryFor(Message $message): array
    {
        /** @var Collection<int, MessageReaction> $reactions */
        $reactions = $message->relationLoaded('reactions')
            ? $message->reactions
            : $message->reactions()->get();

        return $reactions
            ->groupBy('emoji')
            ->map(fn (Collection $group, string $emoji): array => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'reactor_user_ids' => $group
                    ->filter(fn (MessageReaction $r) => $r->reactor_type === SenderType::USER)
                    ->pluck('reactor_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
