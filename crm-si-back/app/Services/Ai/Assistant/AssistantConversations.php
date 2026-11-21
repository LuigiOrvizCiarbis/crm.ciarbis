<?php

namespace App\Services\Ai\Assistant;

use App\Models\TenantMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Vercel\Vercel;

/**
 * Hilos del asistente interno. El participante es la TenantMembership (usuario
 * × workspace): así cada workspace tiene sus propios hilos y nadie puede
 * continuar uno ajeno (el SDK no verifica dueño en continue()).
 */
class AssistantConversations
{
    private const LIST_LIMIT = 20;

    private const TITLE_LENGTH = 50;

    public function __construct(private ConversationStore $store) {}

    /**
     * @return Collection<int, Conversation>
     */
    public function list(TenantMembership $membership): Collection
    {
        return $this->ownedBy($membership)
            ->latest('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get(['id', 'title', 'updated_at']);
    }

    public function create(TenantMembership $membership, string $firstMessage): Conversation
    {
        $id = $this->store->storeConversation(
            $membership->getMorphClass(),
            $membership->getKey(),
            Str::limit(trim($firstMessage), self::TITLE_LENGTH, preserveWords: true),
        );

        return $this->findOwned($membership, $id);
    }

    public function findOwned(TenantMembership $membership, string $id): Conversation
    {
        return $this->ownedBy($membership)->whereKey($id)->firstOrFail();
    }

    /**
     * Mensajes en el formato de useChat, para retomar un hilo.
     *
     * @return list<array<string, mixed>>
     */
    public function uiMessages(Conversation $conversation): array
    {
        // El turno del usuario y la respuesta pueden compartir segundo: el id
        // (UUID v7) desempata en orden de creación.
        return Vercel::toUiMessages($conversation->messages()->oldest()->orderBy('id')->get());
    }

    public function ownsAssistantMessage(TenantMembership $membership, string $messageId): bool
    {
        return ConversationMessage::query()
            ->whereKey($messageId)
            ->where('role', 'assistant')
            ->whereIn('conversation_id', $this->ownedBy($membership)->select('id'))
            ->exists();
    }

    /**
     * @return Builder<Conversation>
     */
    private function ownedBy(TenantMembership $membership): Builder
    {
        return Conversation::query()
            ->where('participant_type', $membership->getMorphClass())
            ->where('participant_id', $membership->getKey());
    }
}
