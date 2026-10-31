<?php

namespace App\Jobs;

use App\Events\ManualAiDraftUpdated;
use App\Models\AiConfig;
use App\Models\Conversation;
use App\Models\ManualAiDraft;
use App\Services\AiReplyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateManualAiDraftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $draftId) {}

    public function handle(AiReplyService $aiReplyService): void
    {
        $draft = ManualAiDraft::withoutGlobalScopes()->find($this->draftId);
        if (! $draft || $draft->status !== 'pending') return;

        $conversation = Conversation::withoutGlobalScopes()->find($draft->conversation_id);
        $source = $conversation?->messages()->withoutTrashed()->find($draft->source_message_id);
        $config = $conversation ? AiConfig::withoutGlobalScopes()->where('tenant_id', $conversation->tenant_id)->first() : null;

        if (! $conversation || ! $source || $conversation->ai_autoreply_enabled || ! $config?->enabled || ! $config->getDecryptedApiKey()) {
            $this->failDraft($draft, 'not_available');
            return;
        }

        $reply = $aiReplyService->respond($conversation, $config);
        $draft->refresh();
        if ($draft->status !== 'pending') return;

        if (! $reply) {
            $this->failDraft($draft, 'provider_error');
            return;
        }

        $draft->update(['status' => 'ready', 'content' => trim($reply), 'expires_at' => now()->addDay()]);
        broadcast(new ManualAiDraftUpdated($draft->fresh()));
    }

    public function failed(\Throwable $exception): void
    {
        $draft = ManualAiDraft::withoutGlobalScopes()->find($this->draftId);
        if ($draft) $this->failDraft($draft, 'provider_error');
        Log::warning('GenerateManualAiDraftJob failed', ['draft_id' => $this->draftId, 'error' => $exception->getMessage()]);
    }

    private function failDraft(ManualAiDraft $draft, string $code): void
    {
        $draft->update(['status' => 'failed', 'error_code' => $code, 'expires_at' => now()->addDay()]);
        broadcast(new ManualAiDraftUpdated($draft->fresh()));
    }
}
