<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Jobs\RefreshAiTypingIndicatorJob;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Tracks one short-lived AI typing session per conversation. */
class AiTypingIndicatorService
{
    private const MAX_SECONDS = 60;

    private const REFRESH_SECONDS = 18;

    private const WHATSAPP_TYPING_SECONDS = 25;

    public function __construct(
        private AiReplyEligibility $eligibility,
        private AiTypingIndicatorTransport $transport,
    ) {}

    public function start(Conversation $conversation, Message $message): void
    {
        if (! $conversation->channel?->isActive()) {
            return;
        }

        $existing = $this->readState($conversation->id);
        $now = now()->timestamp;
        $startedAt = $existing && (int) $existing['expires_at'] > $now
            ? (int) $existing['started_at']
            : $now;
        $state = [
            'message_id' => $message->id,
            'started_at' => $startedAt,
            'expires_at' => $startedAt + self::MAX_SECONDS,
        ];

        if (! $this->saveState($conversation->id, $state)) {
            return;
        }

        try {
            $sent = $this->transport->show($conversation, $message);
        } catch (\Throwable $e) {
            $this->logActionFailure('activar', $conversation->id, $e);
            $sent = false;
        }

        if (! $sent) {
            $this->forgetIfCurrent($conversation->id, $message->id);
            return;
        }

        $remaining = max(1, (int) $state['expires_at'] - now()->timestamp);
        $this->scheduleRefresh($conversation->id, $message->id, min(self::REFRESH_SECONDS, $remaining));
    }

    public function refresh(int $conversationId, int $messageId): void
    {
        $state = $this->readState($conversationId);
        if (! $state || (int) $state['message_id'] !== $messageId) {
            return;
        }

        $conversation = Conversation::withoutGlobalScopes()->find($conversationId);
        if (! $conversation
            || ! $conversation->ai_autoreply_enabled
            || ! $conversation->channel?->isActive()
            || ! $this->eligibility->hasUsableConfig($conversation->tenant_id)
            || now()->timestamp >= (int) $state['expires_at']) {
            $this->stop($conversationId, $messageId);
            return;
        }

        $message = Message::withoutGlobalScopes()->find($messageId);
        if (! $message) {
            $this->stop($conversationId, $messageId);
            return;
        }

        $remaining = (int) $state['expires_at'] - now()->timestamp;
        if ($conversation->channel->type === ChannelType::WHATSAPP
            && $remaining <= self::WHATSAPP_TYPING_SECONDS) {
            $this->finishWhatsAppWindow($conversation, $message, $state);
            return;
        }

        $this->start($conversation, $message);
    }

    public function stop(int $conversationId, ?int $messageId = null): void
    {
        $state = $this->readState($conversationId);
        if (! $state || ($messageId !== null && (int) $state['message_id'] !== $messageId)) {
            return;
        }

        $this->forgetState($conversationId);
        $conversation = Conversation::withoutGlobalScopes()->find($conversationId);
        if (! $conversation) {
            return;
        }

        try {
            $this->transport->hide($conversation);
        } catch (\Throwable $e) {
            $this->logActionFailure('apagar', $conversationId, $e);
        }
    }

    private function finishWhatsAppWindow(Conversation $conversation, Message $message, array $state): void
    {
        if (empty($state['final_wa_refresh'])) {
            try {
                $this->transport->show($conversation, $message);
            } catch (\Throwable $e) {
                $this->logActionFailure('renovar WhatsApp', $conversation->id, $e);
            }

            $state['final_wa_refresh'] = true;
            $this->saveState($conversation->id, $state);
        }

        // WhatsApp has no typing_off action. The final request expires near the cap.
        $remaining = max(1, (int) $state['expires_at'] - now()->timestamp);
        $this->scheduleRefresh($conversation->id, $message->id, $remaining);
    }

    private function readState(int $conversationId): ?array
    {
        try {
            $state = Cache::get($this->cacheKey($conversationId));
            return is_array($state) ? $state : null;
        } catch (\Throwable $e) {
            $this->logActionFailure('leer estado', $conversationId, $e);
            return null;
        }
    }

    private function saveState(int $conversationId, array $state): bool
    {
        try {
            $ttl = max(1, (int) $state['expires_at'] + self::REFRESH_SECONDS - now()->timestamp);
            return Cache::put($this->cacheKey($conversationId), $state, $ttl);
        } catch (\Throwable $e) {
            $this->logActionFailure('guardar estado', $conversationId, $e);
            return false;
        }
    }

    private function forgetIfCurrent(int $conversationId, int $messageId): void
    {
        $state = $this->readState($conversationId);
        if ($state && (int) $state['message_id'] === $messageId) {
            $this->forgetState($conversationId);
        }
    }

    private function forgetState(int $conversationId): void
    {
        try {
            Cache::forget($this->cacheKey($conversationId));
        } catch (\Throwable $e) {
            $this->logActionFailure('limpiar estado', $conversationId, $e);
        }
    }

    private function scheduleRefresh(int $conversationId, int $messageId, int $delaySeconds): void
    {
        if (config('queue.default') === 'sync') {
            return;
        }

        try {
            RefreshAiTypingIndicatorJob::dispatch($conversationId, $messageId)
                ->delay(now()->addSeconds($delaySeconds));
        } catch (\Throwable $e) {
            $this->logActionFailure('programar renovación', $conversationId, $e);
        }
    }

    private function cacheKey(int $conversationId): string
    {
        return 'ai-typing-indicator:'.$conversationId;
    }

    private function logActionFailure(string $action, int $conversationId, \Throwable $error): void
    {
        Log::warning('No se pudo '.$action.' el indicador de escritura de IA', [
            'conversation_id' => $conversationId,
            'error' => $error->getMessage(),
        ]);
    }
}
