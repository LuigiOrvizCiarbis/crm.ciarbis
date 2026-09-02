<?php

namespace App\Automation\Handlers;

use App\Automation\Contracts\ActionHandler;
use App\Automation\Exceptions\ActionSkippedException;
use App\Automation\Exceptions\AmbiguousDeliveryException;
use App\Automation\Exceptions\RetryableActionException;
use App\Enums\ChannelType;
use App\Models\AutomationAction;
use App\Models\AutomationRun;
use App\Models\Channel;
use App\Models\InstagramComment;
use App\Services\InstagramCommentService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

class InstagramPrivateReplyActionHandler implements ActionHandler
{
    public function __construct(private InstagramCommentService $comments) {}

    public function type(): string
    {
        return 'instagram_private_reply';
    }

    public function metadata(): array
    {
        return [
            'label' => 'Responder por privado en Instagram',
            'config_fields' => ['channel_id', 'message'],
        ];
    }

    public function validate(array $config, int $tenantId): array
    {
        $errors = [];
        $channel = Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($config['channel_id'] ?? null);

        if (! $channel || $channel->type !== ChannelType::INSTAGRAM) {
            $errors['channel_id'][] = 'Seleccioná un canal de Instagram del espacio.';
        }

        $message = trim((string) ($config['message'] ?? ''));
        if ($message === '') {
            $errors['message'][] = 'Escribí el mensaje privado.';
        } elseif (mb_strlen($message) > 1000) {
            $errors['message'][] = 'El mensaje no puede superar los 1000 caracteres.';
        }

        return $errors;
    }

    public function preview(AutomationAction $action, AutomationRun $run): array
    {
        return [
            'channel_id' => $action->config['channel_id'] ?? null,
            'message' => $action->config['message'] ?? '',
            'comment_id' => $run->subject_id,
        ];
    }

    public function execute(AutomationAction $action, AutomationRun $run): array
    {
        if ($run->subject_type !== 'instagram_comment') {
            throw new ActionSkippedException('instagram_comment_not_found');
        }

        $comment = $this->claimComment($run, (int) ($action->config['channel_id'] ?? 0));

        if (! $comment) {
            throw new ActionSkippedException('instagram_comment_not_found');
        }
        if (! $comment->channel?->isActive()) {
            throw new ActionSkippedException('channel_inactive');
        }
        try {
            $updated = $this->comments->replyPrivatelyAutomatically(
                $comment,
                trim((string) $action->config['message']),
            );
        } catch (ConnectionException $exception) {
            // No liberamos el claim: Meta pudo haber aceptado el envío antes
            // de que se cortara la conexión. El run queda para revisión.
            throw new AmbiguousDeliveryException('meta_timeout_after_send', previous: $exception);
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '429') || preg_match('/\b5\d\d\b/', $exception->getMessage())) {
                $this->releaseClaim($comment);
                throw new RetryableActionException($exception->getMessage(), previous: $exception);
            }
            $this->releaseClaim($comment);
            throw $exception;
        } catch (\Throwable $exception) {
            $this->releaseClaim($comment);
            throw $exception;
        }

        return [
            'comment_id' => $updated->id,
            'external_id' => $updated->private_reply_external_id,
            'conversation_id' => $updated->conversation_id,
        ];
    }

    private function claimComment(AutomationRun $run, int $channelId): ?InstagramComment
    {
        return DB::transaction(function () use ($run, $channelId): ?InstagramComment {
            $comment = InstagramComment::withoutGlobalScopes()
                ->where('tenant_id', $run->tenant_id)
                ->where('channel_id', $channelId)
                ->whereKey($run->subject_id)
                ->lockForUpdate()
                ->first();

            if (! $comment || ! $comment->channel?->isActive() || ! $comment->privateReplyAvailable()) {
                return null;
            }
            if ($comment->private_reply_claimed_at !== null) {
                return null;
            }

            $comment->update(['private_reply_claimed_at' => now()]);

            return $comment->fresh(['channel.instagramConfig']);
        });
    }

    private function releaseClaim(InstagramComment $comment): void
    {
        InstagramComment::withoutGlobalScopes()
            ->whereKey($comment->id)
            ->whereNull('private_replied_at')
            ->update(['private_reply_claimed_at' => null]);
    }
}
