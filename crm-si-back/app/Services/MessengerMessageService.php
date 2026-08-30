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
use App\Models\Message;
use App\Models\MessengerConfig;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Procesa mensajes entrantes y salientes del canal Facebook Messenger.
 *
 * Comparte el transporte con Instagram (POST /{page_id}/messages, PSID en
 * sender/recipient, is_echo, attachments con URL firmada) pero se mantiene
 * separado de InstagramMessageService: la resolución del canal, la
 * identificación del contacto (PSID vs IGSID), los tipos de attachment y los
 * campos de perfil difieren.
 *
 * Auto-respuesta de IA: las conversaciones heredan ai_autoreply_default de la
 * config del canal, y los mensajes entrantes despachan GenerateAiReplyJob.
 * Handoff: una respuesta humana — desde el CRM o desde la app de Messenger
 * (echo) — apaga el bot en esa conversación.
 */
class MessengerMessageService
{
    /**
     * Tipos de attachment de Messenger que mapeamos a MessageType.
     *
     * Diferencias con Instagram: Messenger soporta `file` (documentos) y no
     * tiene `story_mention`.
     */
    private const ATTACHMENT_TYPE_MAP = [
        'image' => MessageType::Image,
        'audio' => MessageType::Audio,
        'video' => MessageType::Video,
        'file' => MessageType::Document,
        'share' => MessageType::Image,
    ];

    /**
     * Procesa un evento de mensajería entrante de Messenger.
     *
     * @param  array<string, mixed>  $event
     */
    public function processIncomingMessage(?string $entryId, array $event): ?Message
    {
        try {
            $message = $event['message'] ?? null;
            if (! is_array($message)) {
                // Eventos de delivery/read/reaction/postback: fuera de alcance.
                return null;
            }

            $senderId = $event['sender']['id'] ?? null;
            $recipientId = $event['recipient']['id'] ?? null;
            $isEcho = (bool) ($message['is_echo'] ?? false);

            // El PAGE_ID es el recipient en mensajes entrantes, y el sender en
            // los echoes (mensajes que la página envió).
            $pageId = $isEcho ? $senderId : $recipientId;
            // El PSID del contacto es el otro extremo.
            $psid = $isEcho ? $recipientId : $senderId;

            $channel = $this->resolveChannel($entryId, $pageId);
            if (! $channel) {
                Log::warning('Messenger webhook: canal no encontrado', [
                    'entry_id' => $entryId,
                    'page_id' => $pageId,
                ]);

                return null;
            }

            if (! $psid) {
                Log::warning('Messenger webhook: evento sin PSID de contacto', ['entry_id' => $entryId]);

                return null;
            }

            $mid = $message['mid'] ?? null;
            $tenantId = $channel->tenant_id;

            $contact = $this->findOrCreateContact($channel, (string) $psid);
            $conversation = $this->findOrCreateConversation($contact, $channel);

            $extracted = $this->extractContent($message, $tenantId);

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
            Log::error('Error procesando mensaje de Messenger: '.$e->getMessage(), [
                'entry_id' => $entryId,
            ]);

            return null;
        }
    }

    /**
     * Resuelve el canal por page_id.
     *
     * A diferencia de Instagram no hay resolución flexible ni backfill: en
     * Messenger el entry.id es siempre el PAGE_ID, y en un echo el sender.id
     * también, así que no hay ambigüedad que compensar.
     */
    private function resolveChannel(?string $entryId, ?string $pageId): ?Channel
    {
        $candidates = array_values(array_unique(array_filter([$entryId, $pageId])));
        if (empty($candidates)) {
            return null;
        }

        $config = MessengerConfig::whereIn('page_id', $candidates)
            ->with('channels')
            ->first();

        return $config?->channels->first();
    }

    private function findOrCreateContact(Channel $channel, string $psid): Contact
    {
        $contact = Contact::firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                // 'facebook' es el valor que ya validan ContactController y
                // ContactFieldRegistry, y el que el front espera en los filtros.
                'source' => 'facebook',
                'external_id' => $psid,
            ],
            [
                'name' => 'Facebook '.substr($psid, -6),
                'branch_id' => $channel->branch_id,
            ]
        );

        // Best-effort: completar el nombre real desde el perfil la primera vez.
        // No bloquea el flujo si falla (permisos, perfil restringido, etc.).
        if ($contact->wasRecentlyCreated) {
            $this->hydrateContactProfile($contact, $channel, $psid);
        }

        return $contact;
    }

    private function hydrateContactProfile(Contact $contact, Channel $channel, string $psid): void
    {
        try {
            $token = $channel->facebookConfig?->getDecryptedToken();
            if (! $token) {
                return;
            }

            $version = config('services.facebook.graph_version', 'v26.0');
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("https://graph.facebook.com/{$version}/{$psid}", [
                    'fields' => 'first_name,last_name,profile_pic',
                ]);

            if ($response->successful()) {
                $name = trim(($response->json('first_name') ?? '').' '.($response->json('last_name') ?? ''));
                if ($name !== '') {
                    $contact->update(['name' => $name]);
                }
            }
        } catch (\Throwable $e) {
            Log::info('Messenger: no se pudo hidratar el perfil del contacto', [
                'psid' => $psid,
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
                'ai_autoreply_enabled' => (bool) $channel->facebookConfig?->ai_autoreply_default,
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
     * Extrae contenido y media de un mensaje entrante de Messenger.
     *
     * Los tipos no mapeados (template, fallback, location) degradan a texto en
     * lugar de reventar: preferimos un mensaje incompleto en la bandeja antes
     * que perderlo.
     *
     * @param  array<string, mixed>  $message
     * @return array{content: string, type: MessageType, media_url: ?string, media_mime_type: ?string, media_filename: ?string}
     */
    private function extractContent(array $message, int $tenantId): array
    {
        $attachments = $message['attachments'] ?? [];

        if (! empty($attachments) && is_array($attachments)) {
            $attachment = $attachments[0];
            $attachmentType = $attachment['type'] ?? '';
            $url = $attachment['payload']['url'] ?? null;

            // Sin tipo conocido y sin URL no hay media que guardar: lo tratamos
            // como texto para no crear un mensaje de imagen vacío.
            if (! isset(self::ATTACHMENT_TYPE_MAP[$attachmentType]) && ! $url) {
                return [
                    'content' => $message['text'] ?? '',
                    'type' => MessageType::Text,
                    'media_url' => null,
                    'media_mime_type' => null,
                    'media_filename' => null,
                ];
            }

            $type = self::ATTACHMENT_TYPE_MAP[$attachmentType] ?? MessageType::Image;

            $mediaFields = ['url' => null, 'mime_type' => null, 'filename' => null];
            if ($url) {
                // La URL de Meta viene firmada y expira: descargamos directo
                // (sin token) y persistimos el archivo local.
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
     * Descarga media desde la URL firmada de Meta y la guarda en el disco
     * público bajo messages/{tenantId}/.
     *
     * @return array{url: ?string, mime_type: ?string, filename: ?string}
     */
    private function downloadMedia(string $url, int $tenantId): array
    {
        try {
            $response = Http::timeout(30)->get($url);

            if (! $response->successful()) {
                Log::error('Error descargando media de Messenger', ['status' => $response->status()]);

                return ['url' => null, 'mime_type' => null, 'filename' => null];
            }

            $mimeType = $response->header('Content-Type') ?: 'application/octet-stream';
            $mimeType = trim(explode(';', $mimeType)[0]);
            $extension = $this->mimeToExtension($mimeType);
            $filename = uniqid('fb_').'.'.$extension;
            $path = "messages/{$tenantId}/{$filename}";

            Storage::disk('public')->put($path, $response->body());

            return [
                'url' => '/storage/'.$path,
                'mime_type' => $mimeType,
                'filename' => $filename,
            ];
        } catch (\Throwable $e) {
            Log::error('Excepción descargando media de Messenger: '.$e->getMessage());

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
            'audio/ogg' => 'ogg',
            'audio/wav', 'audio/x-wav' => 'wav',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    /**
     * Crea el Message con dedupe por external_id (Meta reintenta y los echoes de
     * mensajes enviados por API llegan de vuelta). Sólo actualiza la conversación
     * y emite el broadcast cuando el mensaje es nuevo.
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
            'document' => '📎 '.($messageData['content'] ?: 'Archivo'),
            default => $messageData['content'] ?? '',
        };

        $conversationUpdates = [
            'last_message_at' => $message->created_at,
            'last_message_content' => $lastContent,
        ];

        // Handoff: un echo que llegó hasta acá fue tipeado por un humano en la
        // app de Messenger (los echoes de mensajes enviados por la API — CRM o
        // IA — se dedupean antes por external_id). Si intervino una persona, el
        // bot se apaga en esta conversación.
        if ($isEcho) {
            $conversationUpdates['ai_autoreply_enabled'] = false;
        }

        Conversation::where('id', $messageData['conversation_id'])->update($conversationUpdates);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $messageData['tenant_id']));
        } catch (\Exception $e) {
            Log::error('Error broadcasting Messenger message: '.$e->getMessage());
        }

        $this->maybeDispatchAiReply($message);

        return $message;
    }

    /**
     * Despacha la auto-respuesta de IA si corresponde: mensaje entrante de
     * texto/imagen del contacto, conversación con auto-respuesta activa y tenant
     * con config de IA habilitada. Mismo criterio y anti-ráfaga (delay +
     * ShouldBeUnique) que los otros canales.
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

        if ($channel->type !== ChannelType::FACEBOOK) {
            throw new \InvalidArgumentException('Solo se pueden enviar mensajes desde conversaciones de Messenger.');
        }

        if (! $channel->isActive()) {
            throw new \InvalidArgumentException('El canal de Messenger está desconectado.');
        }

        $config = $channel->facebookConfig;
        if (! $config || ! $config->page_id) {
            throw new \InvalidArgumentException('El canal no tiene una configuración válida de Messenger.');
        }

        $token = $config->getDecryptedToken();
        if (! $token) {
            throw new \InvalidArgumentException('No se pudo obtener el token de Messenger del canal.');
        }

        $recipientId = $conversation->contact?->external_id;
        if (! $recipientId) {
            throw new \InvalidArgumentException('La conversación no tiene un identificador de Messenger válido.');
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
     *
     * `messaging_type: RESPONSE` es lo que corresponde para responder dentro de
     * la ventana estándar de 24h, que cubre todo el flujo del CRM (el contacto
     * escribe, el agente o la IA responde).
     *
     * $tag queda preparado para la tag HUMAN_AGENT (extiende la ventana a 7 días
     * para respuestas manuales de un agente). Todavía nadie lo pasa: requiere el
     * feature aprobado en App Review, y aplicarlo a la auto-respuesta de IA sería
     * una violación de política de Meta. Cuando esté aprobado, gatear con
     * config('services.messenger.human_agent_enabled') desde los send* de humano.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postMessage(string $pageId, string $token, array $payload, ?string $tag = null): ?string
    {
        $version = config('services.facebook.graph_version', 'v26.0');

        // messaging_type y tag son mutuamente excluyentes: con una tag el tipo
        // pasa a ser MESSAGE_TAG.
        $payload = $tag !== null
            ? [...$payload, 'messaging_type' => 'MESSAGE_TAG', 'tag' => $tag]
            : [...$payload, 'messaging_type' => 'RESPONSE'];

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$pageId}/messages", $payload);

        if (! $response->successful()) {
            throw MetaApiException::fromGraphResponse(ChannelType::FACEBOOK, $response->json());
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
            Log::error('Error broadcasting outbound Messenger message: '.$e->getMessage());
        }

        return $message;
    }

    /**
     * Envía un mensaje de texto generado por el sistema (auto-respuesta IA).
     * Igual que sendTextMessageFromCRM pero con sender_type SYSTEM, sin usuario
     * y sin handoff (el bot no se apaga a sí mismo).
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
            Log::error('Error broadcasting AI outbound Messenger message: '.$e->getMessage());
        }

        return $message;
    }
}
