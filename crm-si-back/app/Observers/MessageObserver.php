<?php

namespace App\Observers;

use App\Events\ManualAiDraftUpdated;
use App\Jobs\FetchLinkPreviewJob;
use App\Models\ManualAiDraft;
use App\Models\Message;
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
    }

    public function updated(Message $message): void
    {
        $message->conversation?->syncLastMessageSummary();
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
