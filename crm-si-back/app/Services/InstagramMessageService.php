<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Exceptions\MetaApiException;
use App\Jobs\GenerateAiReplyJob;
use App\Models\AiConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InstagramConfig;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Procesa mensajes entrantes y salientes del canal Instagram (Instagram
 * Messaging vía Facebook Login). Separado de WhatsAppMessageService a propósito:
 * el transporte, la identificación de contactos (IGSID vs teléfono) y el manejo
 * de media difieren lo suficiente como para no compartir implementación.
 *
 * Auto-respuesta de IA: las conversaciones heredan ai_autoreply_default de la
 * config del canal, y los mensajes entrantes despachan GenerateAiReplyJob
 * (mismas condiciones que WhatsApp). Handoff: una respuesta humana — desde el
 * CRM o desde la app de Instagram (echo) — apaga el bot en esa conversación.
 */
class InstagramMessageService
{
    /** Tipos de attachment de Instagram que mapeamos a MessageType. */
    private const ATTACHMENT_TYPE_MAP = [
        'image' => MessageType::Image,
        'audio' => MessageType::Audio,
        'video' => MessageType::Video,
        'story_mention' => MessageType::Image,
        'share' => MessageType::Image,
    ];

    /**
     * Procesa un evento de mensajería entrante de Instagram.
     *
     * @param  array<string, mixed>  $event
     */
    public function processIncomingMessage(?string $entryId, array $event): ?Message
    {
        try {
            $message = $event['message'] ?? null;
            if (! is_array($message)) {
                // Eventos de delivery/read/reaction: fuera de alcance del MVP.
                return null;
            }

            $senderId = $event['sender']['id'] ?? null;
            $recipientId = $event['recipient']['id'] ?? null;
            $isEcho = (bool) ($message['is_echo'] ?? false);

            // El id de la cuenta del negocio es el recipient en mensajes entrantes,
            // y el sender en los echoes (mensajes que el dueño envió desde la app IG).
            $businessId = $isEcho ? $senderId : $recipientId;
            // El IGSID del contacto es el otro extremo.
            $igsid = $isEcho ? $recipientId : $senderId;

            $channel = $this->resolveChannel($entryId, $businessId);
            if (! $channel) {
                Log::warning('Instagram webhook: canal no encontrado', [
                    'entry_id' => $entryId,
                    'business_id' => $businessId,
                ]);

                return null;
            }

            if (! $igsid) {
                Log::warning('Instagram webhook: evento sin IGSID de contacto', ['entry_id' => $entryId]);

                return null;
            }

            $mid = $message['mid'] ?? null;
            $tenantId = $channel->tenant_id;

            $contact = $this->findOrCreateContact($channel, (string) $igsid);
            $conversation = $this->findOrCreateConversation($contact, $channel);

            $extracted = $this->extractContent($message, $channel, $tenantId);

            return $this->createMessage([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->getKey(),
                'sender_type' => SenderType::CONTACT,
                'sender_id' => $contact->getKey(),
                'content' => $extracted['content'],
                'message_type' => $extracted['type'],
                'media_url' => $extracted['media_url'],
                'media_mime_type' => $extracted['media_mime_type'],
                'media_filename' => $extracted['media_filename'],
                'direction' => $isEcho ? MessageDirection::OUTBOUND : MessageDirection::INBOUND,
                'external_id' => $mid,
                'delivered_at' => now(),
            ], $isEcho);
        } catch (\Throwable $e) {
            Log::error('Error procesando mensaje de Instagram: '.$e->getMessage(), [
                'entry_id' => $entryId,
            ]);

            return null;
        }
    }

    /**
     * Resuelve el canal por webhook_object_id o ig_user_id, cada uno contra
     * entry.id y el id del negocio.
     *
     * NO buscamos por page_id a propósito: en los eventos de Instagram el
     * entry.id y el business id son siempre el IG user id, nunca el page_id. Si
     * lo incluyéramos, un evento de Messenger de la misma página (entry.id =
     * PAGE_ID) matchearía esta config y el mensaje terminaría en el canal de
     * Instagram, además de corromper webhook_object_id vía el backfill.
     */
    private function resolveChannel(?string $entryId, ?string $businessId): ?Channel
    {
        $candidates = array_values(array_unique(array_filter([$entryId, $businessId])));
        if (empty($candidates)) {
            return null;
        }

        $config = InstagramConfig::where(function ($query) use ($candidates) {
            $query->whereIn('webhook_object_id', $candidates)
                ->orWhereIn('ig_user_id', $candidates);
        })->with('channels')->first();

        if (! $config) {
            return null;
        }

        // Backfill sólo cuando nunca se seteó: sobreescribir un valor existente
        // degradaría la resolución si el entry.id llegara con otro id.
        if ($entryId && $config->webhook_object_id === null) {
            $config->update(['webhook_object_id' => $entryId]);
        }

        return $config->channels->first();
    }

    private function findOrCreateContact(Channel $channel, string $igsid): Contact
    {
        $contact = Contact::firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                'source' => 'instagram',
                'external_id' => $igsid,
            ],
            [
                'name' => 'Instagram '.substr($igsid, -6),
                'branch_id' => $channel->branch_id,
            ]
        );

        // Best-effort: completar el nombre real desde el perfil de IG la primera
        // vez. No bloquea el flujo si falla (perfil privado, permisos, etc.).
        if ($contact->wasRecentlyCreated) {
            $this->hydrateContactProfile($contact, $channel, $igsid);
        }

        return $contact;
    }

    private function hydrateContactProfile(Contact $contact, Channel $channel, string $igsid): void
    {
        try {
            $token = $channel->instagramConfig?->getDecryptedToken();
            if (! $token) {
                return;
            }

            $version = config('services.facebook.graph_version', 'v21.0');
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("https://graph.facebook.com/{$version}/{$igsid}", [
                    'fields' => 'name,username',
                ]);

            if ($response->successful()) {
                $name = $response->json('name') ?? $response->json('username');
                if ($name) {
                    $contact->update(['name' => $name]);
                }
            }
        } catch (\Throwable $e) {
            Log::info('Instagram: no se pudo hidratar el perfil del contacto', [
                'igsid' => $igsid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findOrCreateConversation(Contact $contact, Channel $channel): Conversation
    {
        $conversation = Conversation::firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
            ],
            [
                'status' => 'open',
                'last_message_at' => now(),
                'branch_id' => $contact->branch_id ?? $channel->branch_id,
                // El default de auto-respuesta IA se hereda de la config del canal.
                'ai_autoreply_enabled' => (bool) $channel->instagramConfig?->ai_autoreply_default,
            ]
        );

        if (! $conversation->pipeline_stage_id) {
            $defaultStage = PipelineStage::where('tenant_id', $channel->tenant_id)
                ->where(function ($query) {
                    $query->where('is_default', true)
                        ->orWhereNotNull('id');
                })
                ->orderByDesc('is_default')
                ->orderBy('sort_order', 'asc')
                ->first();

            if ($defaultStage) {
                $conversation->update(['pipeline_stage_id' => $defaultStage->id]);
            }
        }

        return $conversation;
    }

    /**
     * Extrae contenido y media de un mensaje entrante de Instagram.
     *
     * @param  array<string, mixed>  $message
     * @return array{content: string, type: MessageType, media_url: ?string, media_mime_type: ?string, media_filename: ?string}
     */
    private function extractContent(array $message, Channel $channel, int $tenantId): array
    {
        $attachments = $message['attachments'] ?? [];

        if (! empty($attachments) && is_array($attachments)) {
            $attachment = $attachments[0];
            $type = self::ATTACHMENT_TYPE_MAP[$attachment['type'] ?? ''] ?? MessageType::Image;
            $url = $attachment['payload']['url'] ?? null;

            $mediaFields = ['url' => null, 'mime_type' => null, 'filename' => null];
            if ($url) {
                // La URL de Meta viene firmada y expira: descargamos directo (sin
                // token) y persistimos el archivo local.
                $mediaFields = $this->downloadMedia($url, $tenantId);
            }

            return [
                'content' => $message['text'] ?? '',
                'type' => $type,
                'media_url' => $mediaFields['url'],
                'media_mime_type' => $mediaFields['mime_type'],
                'media_filename' => $mediaFields['filename'],
            ];
        }

        return [
            'content' => $message['text'] ?? '',
            'type' => MessageType::Text,
            'media_url' => null,
            'media_mime_type' => null,
            'media_filename' => null,
        ];
    }

    /**
     * Descarga media desde la URL firmada de Meta (sin token — a diferencia de
     * WhatsApp que requiere un GET al media id primero) y la guarda en el disco
     * público bajo messages/{tenantId}/.
     *
     * @return array{url: ?string, mime_type: ?string, filename: ?string}
     */
    private function downloadMedia(string $url, int $tenantId): array
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::error('Error descargando media de Instagram', ['status' => $response->status()]);

                return ['url' => null, 'mime_type' => null, 'filename' => null];
            }

            $mimeType = $response->header('Content-Type') ?: 'application/octet-stream';
            $mimeType = trim(explode(';', $mimeType)[0]);
            $extension = $this->mimeToExtension($mimeType);
            $filename = uniqid('ig_').'.'.$extension;
            $path = "messages/{$tenantId}/{$filename}";

            Storage::disk('public')->put($path, $response->body());

            return [
                'url' => '/storage/'.$path,
                'mime_type' => $mimeType,
                'filename' => $filename,
            ];
        } catch (\Throwable $e) {
            Log::error('Excepción descargando media de Instagram: '.$e->getMessage());

            return ['url' => null, 'mime_type' => null, 'filename' => null];
        }
    }

    private function mimeToExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'audio/aac' => 'aac',
            'audio/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            default => 'bin',
        };
    }

    /**
     * Crea el Message con dedupe robusto por external_id (Meta reintenta y los
     * echoes de mensajes enviados por API llegan de vuelta). Sólo actualiza la
     * conversación y emite el broadcast cuando el mensaje es nuevo.
     *
     * @param  array<string, mixed>  $messageData
     */
    private function createMessage(array $messageData, bool $isEcho): ?Message
    {
        $externalId = $messageData['external_id'] ?? null;

        // Dedupe optimista: si ya existe ese mid, no reprocesamos.
        if ($externalId && Message::where('external_id', $externalId)->exists()) {
            return null;
        }

        try {
            $message = Message::create($messageData);
        } catch (QueryException $e) {
            // Carrera: otro request creó el mismo mid entre el exists() y el create().
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }

        $type = $messageData['message_type'] instanceof MessageType
            ? $messageData['message_type']->value
            : ($messageData['message_type'] ?? 'text');

        $lastContent = match ($type) {
            'image' => '📷 '.($messageData['content'] ?: 'Imagen'),
            'video' => '🎥 '.($messageData['content'] ?: 'Video'),
            'audio' => '🎵 Audio',
            default => $messageData['content'] ?? '',
        };

        $conversationUpdates = [
            'last_message_at' => $message->created_at,
            'last_message_content' => $lastContent,
        ];

        // Handoff: un echo que llegó hasta acá fue tipeado por un humano en la
        // app de Instagram (los echoes de mensajes enviados por la API — CRM o
        // IA — se dedupean antes por external_id). Si intervino una persona,
        // el bot se apaga en esta conversación.
        if ($isEcho) {
            $conversationUpdates['ai_autoreply_enabled'] = false;
        }

        Conversation::where('id', $messageData['conversation_id'])->update($conversationUpdates);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $messageData['tenant_id']));
        } catch (\Exception $e) {
            Log::error('Error broadcasting Instagram message: '.$e->getMessage());
        }

        $this->maybeDispatchAiReply($message);

        return $message;
    }

    /**
     * Despacha la auto-respuesta de IA si corresponde: mensaje entrante de
     * texto/imagen del contacto, conversación con auto-respuesta activa y
     * tenant con config de IA habilitada. Mismo criterio y anti-ráfaga
     * (delay + ShouldBeUnique) que el flujo de WhatsApp.
     */
    private function maybeDispatchAiReply(Message $message): void
    {
        if ($message->direction !== MessageDirection::INBOUND
            || $message->sender_type !== SenderType::CONTACT
            || ! in_array($message->message_type, [MessageType::Text, MessageType::Image], true)) {
            return;
        }

        $conversation = Conversation::find($message->conversation_id);
        if (! $conversation || ! $conversation->ai_autoreply_enabled) {
            return;
        }

        // Chequeo barato para no encolar jobs no-op en tenants sin IA activa.
        $hasEnabledAiConfig = AiConfig::withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->where('enabled', true)
            ->exists();

        if (! $hasEnabledAiConfig) {
            return;
        }

        GenerateAiReplyJob::dispatch($conversation->id)
            ->delay(now()->addSeconds(8));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 es unique_violation en Postgres.
        return (string) ($e->getCode()) === '23505'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    // ---------------------------------------------------------------------
    // Envío saliente
    // ---------------------------------------------------------------------

    /**
     * @return array{page_id: string, recipient_id: string, token: string}
     */
    private function resolveOutboundContext(Conversation $conversation): array
    {
        $channel = $conversation->channel;
        if (! $channel) {
            throw new \InvalidArgumentException('La conversación no tiene un canal asociado.');
        }

        if ($channel->type !== ChannelType::INSTAGRAM) {
            throw new \InvalidArgumentException('Solo se pueden enviar mensajes desde conversaciones de Instagram.');
        }

        if (! $channel->isActive()) {
            throw new \InvalidArgumentException('El canal de Instagram está desconectado.');
        }

        $config = $channel->instagramConfig;
        if (! $config || ! $config->page_id) {
            throw new \InvalidArgumentException('El canal no tiene una configuración válida de Instagram.');
        }

        $token = $config->getDecryptedToken();
        if (! $token) {
            throw new \InvalidArgumentException('No se pudo obtener el token de Instagram del canal.');
        }

        $recipientId = $conversation->contact?->external_id;
        if (! $recipientId) {
            throw new \InvalidArgumentException('La conversación no tiene un identificador de Instagram válido.');
        }

        return [
            'page_id' => $config->page_id,
            'recipient_id' => $recipientId,
            'token' => $token,
        ];
    }

    public function sendTextMessageFromCRM(Conversation $conversation, string $content, User $user): Message
    {
        ['page_id' => $pageId, 'recipient_id' => $recipientId, 'token' => $token] =
            $this->resolveOutboundContext($conversation);

        $externalId = $this->postMessage($pageId, $token, [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $content],
        ]);

        return $this->persistOutbound($conversation, $user, [
            'content' => $content,
            'message_type' => MessageType::Text,
            'external_id' => $externalId,
            'last_content' => $content,
        ]);
    }

    public function sendImageMessageFromCRM(
        Conversation $conversation,
        string $localMediaPath,
        string $mediaUrl,
        string $mimeType,
        ?string $caption,
        User $user
    ): Message {
        ['page_id' => $pageId, 'recipient_id' => $recipientId, 'token' => $token] =
            $this->resolveOutboundContext($conversation);

        $externalId = $this->postMessage($pageId, $token, [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'image',
                    'payload' => [
                        'url' => $this->publicMediaUrl($mediaUrl),
                        'is_reusable' => false,
                    ],
                ],
            ],
        ]);

        return $this->persistOutbound($conversation, $user, [
            'content' => $caption ?? '',
            'message_type' => MessageType::Image,
            'external_id' => $externalId,
            'media_url' => $mediaUrl,
            'media_mime_type' => $mimeType,
            'media_filename' => basename($localMediaPath),
            'last_content' => '📷 '.($caption ?: 'Imagen'),
        ]);
    }

    public function sendAudioMessageFromCRM(
        Conversation $conversation,
        string $localMediaPath,
        string $mediaUrl,
        string $mimeType,
        User $user
    ): Message {
        ['page_id' => $pageId, 'recipient_id' => $recipientId, 'token' => $token] =
            $this->resolveOutboundContext($conversation);

        $externalId = $this->postMessage($pageId, $token, [
            'recipient' => ['id' => $recipientId],
            'message' => [
                'attachment' => [
                    'type' => 'audio',
                    'payload' => [
                        'url' => $this->publicMediaUrl($mediaUrl),
                        'is_reusable' => false,
                    ],
                ],
            ],
        ]);

        return $this->persistOutbound($conversation, $user, [
            'content' => '',
            'message_type' => MessageType::Audio,
            'external_id' => $externalId,
            'media_url' => $mediaUrl,
            'media_mime_type' => $mimeType,
            'media_filename' => basename($localMediaPath),
            'last_content' => '🎵 Audio',
        ]);
    }

    /**
     * POST al endpoint de mensajes de la página. Devuelve el message_id de Meta.
     * Mapea los errores de Graph a MetaApiException tipada.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postMessage(string $pageId, string $token, array $payload): ?string
    {
        $version = config('services.facebook.graph_version', 'v21.0');

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$pageId}/messages", $payload);

        if (! $response->successful()) {
            throw MetaApiException::fromGraphResponse(ChannelType::INSTAGRAM, $response->json());
        }

        return $response->json('message_id');
    }

    /**
     * Construye la URL pública absoluta desde la que Meta descargará la media.
     */
    private function publicMediaUrl(string $relativeUrl): string
    {
        if (str_starts_with($relativeUrl, 'http')) {
            return $relativeUrl;
        }

        $base = config('services.facebook.public_media_base_url') ?: config('app.url');

        return rtrim((string) $base, '/').$relativeUrl;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persistOutbound(Conversation $conversation, User $user, array $data): Message
    {
        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => $data['content'],
            'message_type' => $data['message_type'],
            'media_url' => $data['media_url'] ?? null,
            'media_mime_type' => $data['media_mime_type'] ?? null,
            'media_filename' => $data['media_filename'] ?? null,
            'direction' => MessageDirection::OUTBOUND,
            'delivered_at' => now(),
            'external_id' => $data['external_id'],
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => $data['last_content'],
            // Handoff: si un humano responde desde el CRM, el bot se apaga.
            'ai_autoreply_enabled' => false,
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting outbound Instagram message: '.$e->getMessage());
        }

        return $message;
    }

    /**
     * Envía un mensaje de texto generado por el sistema (auto-respuesta IA).
     * Igual que sendTextMessageFromCRM pero con sender_type SYSTEM, sin usuario
     * y sin handoff (el bot no se apaga a sí mismo). Corre dentro de
     * GenerateAiReplyJob (worker de colas): el timeout de postMessage evita
     * bloquear el worker si Graph se cuelga.
     */
    public function sendSystemTextMessageFromCRM(Conversation $conversation, string $content): Message
    {
        ['page_id' => $pageId, 'recipient_id' => $recipientId, 'token' => $token] =
            $this->resolveOutboundContext($conversation);

        $externalId = $this->postMessage($pageId, $token, [
            'recipient' => ['id' => $recipientId],
            'message' => ['text' => $content],
        ]);

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::SYSTEM,
            'content' => $content,
            'direction' => MessageDirection::OUTBOUND,
            'delivered_at' => now(),
            'message_type' => MessageType::Text,
            'external_id' => $externalId,
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => $content,
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting AI outbound Instagram message: '.$e->getMessage());
        }

        return $message;
    }
}
