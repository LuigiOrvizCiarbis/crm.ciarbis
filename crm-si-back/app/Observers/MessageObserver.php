<?php

namespace App\Observers;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Events\ManualAiDraftUpdated;
use App\Jobs\FetchLinkPreviewJob;
use App\Jobs\NotifyInboundMessageJob;
use App\Models\ManualAiDraft;
use App\Models\Message;
use App\Services\HumanHandoffService;
use App\Services\LinkPreviewService;

class MessageObserver
{
    public function created(Message $message): void
    {
        $message->conversation?->syncLastMessageSummary();
        $drafts = ManualAiDraft::withoutGlobalScopes()
            ->where('conversation_id', $message->conversation_id)
            ->whereIn('status', ['pending', 'ready'])
            ->get();

        foreach ($drafts as $draft) {
            $draft->update(['status' => 'cancelled']);
            broadcast(new ManualAiDraftUpdated($draft->fresh()));
        }

        if (app(LinkPreviewService::class)->extractFirstUrl($message->content) !== null) {
            FetchLinkPreviewJob::dispatch($message->id);
        }

        if ($message->direction === MessageDirection::INBOUND && $message->sender_type === SenderType::CONTACT) {
            NotifyInboundMessageJob::dispatch($message->id)->afterCommit();
        }

        // Cualquier mensaje humano saliente cierra la derivación: CRM,
        // templates, programados y también los que el negocio manda desde el
        // celular (echoes de coexistence), que no pasan por MessageController.
        if ($message->direction === MessageDirection::OUTBOUND && $message->sender_type === SenderType::USER && $message->conversation) {
            app(HumanHandoffService::class)->resolveForHumanMessage($message->conversation, $message->created_at);
        }
    }

    public function updated(Message $message): void
    {
        if (array_intersect(array_keys($message->getChanges()), ['content', 'message_type', 'created_at', 'conversation_id']) !== []) {
            $message->conversation?->syncLastMessageSummary();
        }
    }

    public function deleted(Message $message): void
    {
        $message->conversation?->syncLastMessageSummary();
    }

    public function restored(Message $message): void
    {
        $message->conversation?->syncLastMessageSummary();
    }
}
