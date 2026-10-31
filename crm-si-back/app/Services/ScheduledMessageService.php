<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Enums\ScheduledMessageStatus;
use App\Events\ScheduledMessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Alta, edición y cancelación de mensajes programados desde el chat. El envío
 * real lo hace SendScheduledMessageJob cuando vence `scheduled_for`.
 *
 * Los errores de negocio se lanzan como InvalidArgumentException con un texto
 * listo para mostrar: el controller los devuelve como 422.
 */
class ScheduledMessageService
{
    public const MAX_CONTENT_LENGTH = 4096;

    public const MAX_DAYS_AHEAD = 60;

    /** Ventana de atención de Meta: sólo se puede escribir libre hasta 24h después del último entrante. */
    private const MESSAGING_WINDOW_HOURS = 24;

    public function __construct(private VoiceTranscoder $voiceTranscoder) {}

    /**
     * @param  array{type: string, content?: ?string, scheduled_for: string, voice?: bool}  $data
     */
    public function schedule(Conversation $conversation, User $user, array $data, ?UploadedFile $file): ScheduledMessage
    {
        $type = $data['type'];
        $voice = (bool) ($data['voice'] ?? false);
        $this->ensureSchedulable($conversation, $type, $voice, $file);

        [$mediaPath, $mediaMime] = $file !== null
            ? $this->storeMedia($file, $conversation->tenant_id, $voice)
            : [null, null];

        $scheduledMessage = ScheduledMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'message_type' => $type,
            'content' => $this->normalizeContent($data['content'] ?? null),
            'media_path' => $mediaPath,
            'media_mime' => $mediaMime,
            'voice' => $voice,
            'scheduled_for' => CarbonImmutable::parse($data['scheduled_for']),
            'status' => ScheduledMessageStatus::Scheduled,
        ]);

        return $this->announce($scheduledMessage);
    }

    /**
     * Cambia texto y/o fecha. Un fallido vuelve a quedar programado.
     *
     * @param  array{content?: ?string, scheduled_for?: string}  $data
     */
    public function reschedule(ScheduledMessage $scheduledMessage, array $data): ScheduledMessage
    {
        $changes = ['status' => ScheduledMessageStatus::Scheduled, 'error_reason' => null, 'error_message' => null];

        if (array_key_exists('content', $data)) {
            $content = $this->normalizeContent($data['content']);
            if ($content === null && $scheduledMessage->message_type === 'text') {
                throw new InvalidArgumentException('El mensaje no puede quedar vacío.');
            }
            $changes['content'] = $content;
        }

        if (isset($data['scheduled_for'])) {
            $changes['scheduled_for'] = CarbonImmutable::parse($data['scheduled_for']);
        } elseif ($scheduledMessage->status === ScheduledMessageStatus::Failed) {
            throw new InvalidArgumentException('Elegí una fecha nueva para reprogramar el mensaje.');
        }

        $this->transition($scheduledMessage, $this->editableStatuses(), $changes);

        return $this->announce($scheduledMessage->refresh());
    }

    public function cancel(ScheduledMessage $scheduledMessage): void
    {
        $cancellable = array_values(array_filter(
            ScheduledMessageStatus::cases(),
            fn (ScheduledMessageStatus $status) => $status->isCancellable(),
        ));

        $this->transition($scheduledMessage, $cancellable, ['status' => ScheduledMessageStatus::Cancelled]);
        $scheduledMessage->refresh()->deleteMedia();
        $this->announce($scheduledMessage);
    }

    /**
     * Hasta cuándo se puede escribir sin plantilla. Null si el contacto nunca
     * escribió o el canal no tiene ventana (sólo aplica a Meta).
     */
    public function windowExpiresAt(Conversation $conversation): ?CarbonInterface
    {
        if (! in_array($conversation->channel?->type, OutboundMessageSender::SCHEDULABLE_CHANNELS, true)) {
            return null;
        }

        $lastInbound = Message::query()
            ->where('conversation_id', $conversation->id)
            ->inbound()
            ->latest('created_at')
            ->value('created_at');

        return $lastInbound !== null
            ? CarbonImmutable::parse($lastInbound)->addHours(self::MESSAGING_WINDOW_HOURS)
            : null;
    }

    private function ensureSchedulable(Conversation $conversation, string $type, bool $voice, ?UploadedFile $file): void
    {
        $channelType = $conversation->channel?->type;

        if (! in_array($channelType, OutboundMessageSender::SCHEDULABLE_CHANNELS, true)) {
            throw new InvalidArgumentException('Sólo se pueden programar mensajes de WhatsApp, Instagram o Messenger.');
        }

        if ($voice && ($type !== 'audio' || $channelType !== ChannelType::WHATSAPP)) {
            throw new InvalidArgumentException('voice sólo está disponible para audios de WhatsApp.');
        }

        if ($voice && $conversation->isGroup()) {
            throw new InvalidArgumentException('Los mensajes de voz no están disponibles en grupos.');
        }

        // Instagram sólo acepta ciertos formatos de audio; mejor rechazarlo
        // ahora que enterarse cuando el mensaje falle a la hora programada.
        $instagramAudio = ['audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav'];
        if ($type === 'audio' && $channelType === ChannelType::INSTAGRAM
            && ! in_array($file?->getMimeType(), $instagramAudio, true)) {
            throw new InvalidArgumentException('Instagram no admite este formato de audio. Usá AAC, M4A o WAV.');
        }
    }

    /**
     * Guarda el adjunto en el mismo lugar que el envío inmediato. Las notas de
     * voz se transcodifican ya: si ffmpeg falla, el usuario se entera ahora.
     *
     * @return array{0: string, 1: string}
     */
    private function storeMedia(UploadedFile $file, int $tenantId, bool $voice): array
    {
        if (! $voice) {
            $fallbackMime = str_starts_with((string) $file->getMimeType(), 'image/') ? 'image/jpeg' : 'audio/mpeg';

            return [$file->store("messages/{$tenantId}", 'public'), $file->getMimeType() ?: $fallbackMime];
        }

        $voicePath = $this->voiceTranscoder->transcode($file->getRealPath());
        try {
            $path = "messages/{$tenantId}/".uniqid('voice_', true).'.ogg';
            Storage::disk('public')->put($path, file_get_contents($voicePath));

            return [$path, 'audio/ogg'];
        } finally {
            if (is_file($voicePath)) {
                unlink($voicePath);
            }
        }
    }

    /**
     * Update condicional: si el dispatcher ya lo tomó (queued/sending) entre
     * que el usuario abrió la lista y confirmó, no se pisa el envío en curso.
     *
     * @param  list<ScheduledMessageStatus>  $from
     * @param  array<string, mixed>  $changes
     */
    private function transition(ScheduledMessage $scheduledMessage, array $from, array $changes): void
    {
        // Pasar por fill() para que los casts y HasTimezoneAwareDates
        // serialicen las fechas con offset, igual que un save().
        $attributes = (clone $scheduledMessage)->fill($changes)->getDirty();

        $updated = ScheduledMessage::query()
            ->whereKey($scheduledMessage->id)
            ->whereIn('status', $from)
            ->update($attributes);

        if ($updated === 0) {
            throw new InvalidArgumentException('Este mensaje ya se envió o se está enviando.');
        }
    }

    /**
     * @return list<ScheduledMessageStatus>
     */
    private function editableStatuses(): array
    {
        return array_values(array_filter(
            ScheduledMessageStatus::cases(),
            fn (ScheduledMessageStatus $status) => $status->isEditable(),
        ));
    }

    private function normalizeContent(?string $content): ?string
    {
        $content = trim((string) $content);

        return $content === '' ? null : $content;
    }

    private function announce(ScheduledMessage $scheduledMessage): ScheduledMessage
    {
        broadcast(new ScheduledMessageUpdated($scheduledMessage->loadMissing('user')));

        return $scheduledMessage;
    }
}
