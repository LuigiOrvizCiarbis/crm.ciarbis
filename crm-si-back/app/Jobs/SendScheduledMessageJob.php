<?php

namespace App\Jobs;

use App\Enums\ScheduledMessageStatus;
use App\Events\ScheduledMessageUpdated;
use App\Exceptions\MetaApiException;
use App\Models\Conversation;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Services\Notifications\DomainNotifier;
use App\Services\Notifications\NotificationRecipients;
use App\Services\OutboundMessageSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Envía un mensaje programado cuando vence. Lo encola
 * `messages:dispatch-scheduled` después de pasarlo a Queued.
 *
 * - tries=1: Meta puede haber aceptado el mensaje aunque el job falle
 *   después; reintentar duplicaría el envío al contacto.
 * - Claim queued → sending con update condicional: si el dispatcher reencola
 *   un job que en realidad sí corrió, el segundo no encuentra nada que tomar.
 */
class SendScheduledMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $scheduledMessageId) {}

    public function handle(
        OutboundMessageSender $sender,
        DomainNotifier $notifier,
        NotificationRecipients $recipients,
        PermissionRegistrar $registrar,
    ): void {
        if (! $this->claim()) {
            return;
        }

        // El job corre sin usuario autenticado: TenantScope no filtra nada,
        // por eso withoutGlobalScopes + filtro manual por tenant.
        $scheduledMessage = ScheduledMessage::withoutGlobalScopes()->findOrFail($this->scheduledMessageId);
        $conversation = Conversation::withoutGlobalScopes()
            ->with(['channel.whatsappConfig', 'channel.instagramConfig', 'channel.facebookConfig', 'contact'])
            ->where('tenant_id', $scheduledMessage->tenant_id)
            ->find($scheduledMessage->conversation_id);

        // El usuario se resuelve con su membresía del tenant del mensaje: si
        // lo dieron de baja del workspace, no aparece y el envío no sale.
        $user = $recipients->users($scheduledMessage->tenant_id, [(int) $scheduledMessage->user_id])->first();

        if ($conversation === null || $user === null || ! $this->canStillSend($registrar, $user, $conversation)) {
            $this->markFailed($scheduledMessage, 'permission_lost',
                'Quien programó el mensaje ya no puede escribir en esta conversación.', $notifier, $conversation);

            return;
        }

        try {
            $message = $sender->send(
                $conversation, $user, $scheduledMessage->message_type, $scheduledMessage->content,
                $scheduledMessage->media_path, $scheduledMessage->media_mime, $scheduledMessage->voice,
            );
        } catch (MetaApiException $e) {
            if ($e->reason === MetaApiException::REASON_TOKEN_INVALID && $conversation->channel) {
                $notifier->channelTokenInvalid($conversation->channel);
            }
            $this->markFailed($scheduledMessage, $e->reason, $e->userMessage(), $notifier, $conversation);

            return;
        } catch (\InvalidArgumentException $e) {
            // Canal desconectado o sin configuración entre que se programó y
            // ahora: los servicios lo reportan con un texto ya accionable
            // (el mismo que devuelve el envío inmediato como 422).
            $this->markFailed($scheduledMessage, 'channel_unavailable', $e->getMessage(), $notifier, $conversation);

            return;
        } catch (\RuntimeException $e) {
            Log::error('No se pudo enviar un mensaje programado', [
                'scheduled_message_id' => $scheduledMessage->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($scheduledMessage, 'unknown',
                'No se pudo enviar el mensaje. Verificá la configuración del canal e intentá de nuevo.', $notifier, $conversation);

            return;
        }

        $scheduledMessage->update([
            'status' => ScheduledMessageStatus::Sent,
            'sent_at' => now()->utc(),
            'message_id' => $message->id,
        ]);
        broadcast(new ScheduledMessageUpdated($scheduledMessage));
    }

    /**
     * Timeout o error inesperado: si quedó en sending, se marca fallido para
     * que no quede trabado en la barra ni se reintente solo.
     */
    public function failed(?Throwable $exception): void
    {
        $scheduledMessage = ScheduledMessage::withoutGlobalScopes()->find($this->scheduledMessageId);
        if ($scheduledMessage?->status !== ScheduledMessageStatus::Sending) {
            return;
        }

        Log::error('SendScheduledMessageJob falló', [
            'scheduled_message_id' => $this->scheduledMessageId,
            'error' => $exception?->getMessage(),
        ]);

        // failed() corre fuera de handle(): sin inyección de dependencias ni
        // usuario autenticado, por eso el container y el filtro manual de tenant.
        $conversation = Conversation::withoutGlobalScopes()
            ->with('contact')
            ->where('tenant_id', $scheduledMessage->tenant_id)
            ->find($scheduledMessage->conversation_id);

        $this->markFailed($scheduledMessage, 'unknown',
            'No se pudo confirmar el envío. Revisá el chat antes de reprogramarlo.', app(DomainNotifier::class), $conversation);
    }

    private function claim(): bool
    {
        return ScheduledMessage::withoutGlobalScopes()
            ->whereKey($this->scheduledMessageId)
            ->where('status', ScheduledMessageStatus::Queued)
            ->update(['status' => ScheduledMessageStatus::Sending]) === 1;
    }

    /**
     * Los permisos de Spatie dependen del team (tenant) activo, que en un job
     * no lo fija ningún middleware.
     */
    private function canStillSend(PermissionRegistrar $registrar, User $user, Conversation $conversation): bool
    {
        $previous = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($conversation->tenant_id);

        try {
            return Gate::forUser($user)->allows('sendMessage', $conversation);
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    private function markFailed(
        ScheduledMessage $scheduledMessage,
        string $reason,
        string $errorMessage,
        DomainNotifier $notifier,
        ?Conversation $conversation,
    ): void {
        $scheduledMessage->update([
            'status' => ScheduledMessageStatus::Failed,
            'error_reason' => $reason,
            'error_message' => $errorMessage,
        ]);
        broadcast(new ScheduledMessageUpdated($scheduledMessage));

        if ($conversation !== null) {
            $notifier->scheduledMessageFailed($scheduledMessage, $conversation);
        }
    }
}
