<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Events\MessageSent;
use App\Events\MessageStatusUpdated;
use App\Events\TenantMessageReceived;
use App\Jobs\GenerateAiReplyJob;
use App\Models\AiConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\Concerns\ResolvesWhatsAppChannel;
use App\Services\Concerns\ResolvesWhatsAppCredentials;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppMessageService
{
    use ResolvesWhatsAppChannel;
    use ResolvesWhatsAppCredentials;

    private function graphVersion(): string
    {
        return config('services.facebook.graph_version', 'v26.0');
    }

    public function processIncomingMessage(array $webhookData): ?Message
    {
        try {
            $value = $webhookData['value'] ?? null;
            if (! is_array($value)) {
                Log::warning('Payload sin value válido');

                return null;
            }

            $messages = $value['messages'] ?? [];
            if (empty($messages)) {
                Log::info('Webhook sin messages, nada que procesar');

                return null;
            }

            $channel = $this->resolveChannelFromWebhook($value, 'processIncomingMessage');
            if (! $channel) {
                return null;
            }

            $messageData = $messages[0];
            $contactData = $value['contacts'][0] ?? null;
            $tenantId = $channel->tenant_id;
            $messageType = $messageData['type'] ?? 'unknown';
            $messageId = $messageData['id'] ?? null;

            Log::info('WhatsApp incoming message', [
                'type' => $messageType,
                'id' => $messageId,
                'keys' => array_keys($messageData),
                'has_context' => isset($messageData['context']),
                'context' => $messageData['context'] ?? null,
                'has_errors' => isset($messageData['errors']),
                'errors' => $messageData['errors'] ?? null,
                'tenant_id' => $tenantId,
            ]);

            // Mensaje eliminado por el contacto desde su celular ("delete for everyone").
            // Meta envía type: "unsupported" con errors[].code = 131051. El id del
            // mensaje original viene en context.id (no en el id top-level, que es un id nuevo del evento).
            if ($this->isMessageDeletionEvent($messageData)) {
                $deletedId = $messageData['revoke']['original_message_id']
                    ?? $messageData['context']['id']
                    ?? $messageId;
                $this->handleIncomingMessageDeleted($deletedId, $tenantId);

                return null;
            }

            // Mensaje editado por el contacto desde su celular.
            // Meta puede enviarlo de varias formas: con context.edited: true, con un
            // top-level edit, o como un mensaje text cuyo context.id apunta a un
            // mensaje existente y además marca explícitamente la edición.
            $originalEditedId = $this->detectEditedMessageOriginalId($messageData, $tenantId);
            if ($originalEditedId) {
                return $this->handleIncomingMessageEdited($messageData, $originalEditedId, $tenantId);
            }

            if (! $this->isSupportedMessageType($messageType)) {
                Log::warning('WhatsApp message type no soportado, payload ignorado', [
                    'type' => $messageType,
                    'message_data' => $messageData,
                    'tenant_id' => $tenantId,
                ]);

                return null;
            }

            /** @var Contact $contact */
            $contact = $this->findOrCreateContact($contactData, $messageData['from'] ?? '', $channel);
            /** @var Conversation $conversation */
            $conversation = $this->findOrCreateConversation($contact, $channel);
            /** @var int $conversationId */
            $conversationId = $conversation->getKey();
            /** @var int $contactId */
            $contactId = $contact->getKey();

            $extracted = $this->extractMessageData($messageData);

            $mediaFields = [];
            if ($extracted['media_id'] && $extracted['type'] !== 'text') {
                $waConfig = $channel->WhatsAppConfig;
                if ($waConfig) {
                    $accessToken = Crypt::decryptString($waConfig->bussines_token);
                    $mediaFields = $this->downloadWhatsAppMedia(
                        $extracted['media_id'],
                        $accessToken,
                        $tenantId
                    );
                }
            }

            return $this->createMessage([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'sender_type' => SenderType::CONTACT,
                'sender_id' => $contactId,
                'content' => $extracted['content'],
                'message_type' => $extracted['type'],
                'media_url' => $mediaFields['url'] ?? null,
                'media_mime_type' => $mediaFields['mime_type'] ?? null,
                'media_filename' => $mediaFields['filename'] ?? null,
                'direction' => MessageDirection::INBOUND,
                'external_id' => $messageData['id'] ?? null,
                'delivered_at' => $this->parseWebhookTimestamp($messageData['timestamp'] ?? null),
            ]);
        } catch (\Exception $e) {
            Log::error('Error procesando mensaje de WhatsApp: '.$e->getMessage(), [
                'exception' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function findOrCreateContact(?array $contactData, string $phoneNumber, Channel $channel): Contact
    {
        $name = 'Sin nombre';
        if ($contactData && isset($contactData['profile']['name'])) {
            $name = $contactData['profile']['name'];
        }

        return Contact::firstOrCreate(
            [
                'tenant_id' => $channel->tenant_id,
                'phone' => $phoneNumber,
            ],
            [
                'name' => $name,
                'source' => 'whatsapp',
                'branch_id' => $channel->branch_id,
            ]
        );
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
                'ai_autoreply_enabled' => (bool) $channel->whatsappConfig?->ai_autoreply_default,
            ]
        );

        // Si es una conversación nueva sin stage, asignar el stage por defecto
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

    private function parseWebhookTimestamp(?string $timestamp): Carbon
    {
        if ($timestamp) {
            return Carbon::createFromTimestamp((int) $timestamp)->setTimezone(config('app.timezone'));
        }

        return Carbon::now();
    }

    public function sendImageMessageFromCRM(
        Conversation $conversation,
        string $localMediaPath,
        string $mediaUrl,
        string $mimeType,
        ?string $caption,
        User $user
    ): Message {
        ['to' => $to, 'recipient_type' => $recipientType, 'business_phone_id' => $businessPhoneId, 'business_token' => $businessToken] =
            $this->resolveOutboundWhatsAppContext($conversation);

        $uploadResponse = Http::withToken($businessToken)
            ->timeout(30)
            ->attach('file', Storage::disk('public')->get($localMediaPath), basename($localMediaPath), ['Content-Type' => $mimeType])
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$businessPhoneId}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        if (! $uploadResponse->successful()) {
            throw new \RuntimeException('Error subiendo imagen a WhatsApp: '.$uploadResponse->body());
        }

        $whatsappMediaId = $uploadResponse->json('id');

        $messagePayload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => $recipientType,
            'to' => $to,
            'type' => 'image',
            'image' => [
                'id' => $whatsappMediaId,
            ],
        ];

        if ($caption) {
            $messagePayload['image']['caption'] = $caption;
        }

        $sendResponse = Http::withToken($businessToken)
            ->timeout(10)
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$businessPhoneId}/messages", $messagePayload);

        if (! $sendResponse->successful()) {
            throw new \RuntimeException('Error enviando imagen por WhatsApp: '.$sendResponse->body());
        }

        $externalId = $sendResponse->json('messages.0.id');

        $userId = $user->getKey();

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $userId,
            'content' => $caption ?? '',
            'message_type' => MessageType::Image,
            'media_url' => $mediaUrl,
            'media_mime_type' => $mimeType,
            'media_filename' => basename($localMediaPath),
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => $externalId,
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => '📷 '.($caption ?: 'Imagen'),
            // Handoff: si un humano responde, el bot se apaga en esta conversación.
            'ai_autoreply_enabled' => false,
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting outbound image message: '.$e->getMessage());
        }

        return $message;
    }

    /**
     * WhatsApp Cloud API sólo acepta audio/aac, audio/mp4, audio/mpeg, audio/amr
     * y audio/ogg (opus). webm y wav pasan la validación general del CRM (son
     * lo que graba el navegador en mobile) pero Meta los rechaza en el upload
     * a /media. Los convertimos acá a ogg/opus, el formato nativo de las notas
     * de voz de WhatsApp, antes de subir.
     *
     * Devuelve [path local (posiblemente convertido), mime, nombre de archivo].
     * Si el mime ya es compatible con Meta, no toca nada.
     */
    private function ensureWhatsAppCompatibleAudio(string $localMediaPath, string $mimeType): array
    {
        // PHP/fileinfo suele detectar los .m4a como audio/x-m4a, pero Meta sólo
        // acepta ese contenedor con el MIME estándar audio/mp4.
        if ($mimeType === 'audio/x-m4a') {
            return [$localMediaPath, 'audio/mp4', basename($localMediaPath)];
        }

        $metaCompatible = ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/mp3', 'audio/amr', 'audio/3gpp', 'audio/ogg'];
        $needsTranscode = ! in_array($mimeType, $metaCompatible, true);

        if (! $needsTranscode) {
            return [$localMediaPath, $mimeType, basename($localMediaPath)];
        }

        if (! $this->ffmpegAvailable()) {
            throw new \InvalidArgumentException(
                'Este formato de audio no es compatible con WhatsApp y el servidor no puede convertirlo. Probá grabar de nuevo o adjuntar un MP3, OGG o M4A.'
            );
        }

        $sourceAbsolutePath = Storage::disk('public')->path($localMediaPath);
        // Guardamos el convertido junto al original (mismo directorio "messages/{tenant}")
        // con nombre nuevo; si el path viniera plano, dirname() devuelve "." y el
        // resultado queda igual de válido para Storage::disk('public').
        $convertedRelativePath = ($dir = dirname($localMediaPath)) !== '.'
            ? $dir.'/'.Str::uuid().'.ogg'
            : Str::uuid().'.ogg';
        $convertedAbsolutePath = Storage::disk('public')->path($convertedRelativePath);

        $result = Process::timeout(30)->run([
            'ffmpeg', '-y',
            '-i', $sourceAbsolutePath,
            '-c:a', 'libopus',
            '-b:a', '64k',
            '-vn',
            $convertedAbsolutePath,
        ]);

        if ($result->failed() || ! is_file($convertedAbsolutePath)) {
            Log::error('Error transcodificando audio a ogg/opus para WhatsApp', [
                'source' => $localMediaPath,
                'mime' => $mimeType,
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);

            throw new \InvalidArgumentException(
                'No se pudo convertir el audio a un formato compatible con WhatsApp. Probá grabar de nuevo.'
            );
        }

        return [$convertedRelativePath, 'audio/ogg', basename($convertedRelativePath)];
    }

    private function ffmpegAvailable(): bool
    {
        return Process::run(['which', 'ffmpeg'])->successful();
    }

    public function sendAudioMessageFromCRM(
        Conversation $conversation,
        string $localMediaPath,
        string $mediaUrl,
        string $mimeType,
        User $user,
        bool $voice = false
    ): Message {
        ['to' => $to, 'recipient_type' => $recipientType, 'business_phone_id' => $businessPhoneId, 'business_token' => $businessToken] =
            $this->resolveOutboundWhatsAppContext($conversation);

        [$uploadMediaPath, $uploadMimeType, $uploadFilename] =
            $this->ensureWhatsAppCompatibleAudio($localMediaPath, $mimeType);
        $wasTranscoded = $uploadMediaPath !== $localMediaPath;

        $uploadResponse = Http::withToken($businessToken)
            ->timeout(30)
            ->attach('file', Storage::disk('public')->get($uploadMediaPath), $uploadFilename, ['Content-Type' => $uploadMimeType])
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$businessPhoneId}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $uploadMimeType,
            ]);

        // El .ogg convertido es sólo para el upload a Meta; el CRM conserva y
        // reproduce el archivo original. Lo borramos apenas termina el upload.
        if ($wasTranscoded) {
            Storage::disk('public')->delete($uploadMediaPath);
        }

        if (! $uploadResponse->successful()) {
            throw new \RuntimeException('Error subiendo audio a WhatsApp: '.$uploadResponse->body());
        }

        $whatsappMediaId = $uploadResponse->json('id');

        $sendResponse = Http::withToken($businessToken)
            ->timeout(10)
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$businessPhoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => $recipientType,
                'to' => $to,
                'type' => 'audio',
                'audio' => [
                    'id' => $whatsappMediaId,
                    ...($voice ? ['voice' => true] : []),
                ],
            ]);

        if (! $sendResponse->successful()) {
            throw new \RuntimeException('Error enviando audio por WhatsApp: '.$sendResponse->body());
        }

        $externalId = $sendResponse->json('messages.0.id');

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => '',
            'message_type' => MessageType::Audio,
            'media_url' => $mediaUrl,
            'media_mime_type' => $mimeType,
            'media_filename' => basename($localMediaPath),
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => $externalId,
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => '🎵 Audio',
            // Handoff: si un humano responde, el bot se apaga en esta conversación.
            'ai_autoreply_enabled' => false,
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting outbound audio message: '.$e->getMessage());
        }

        return $message;
    }

    /**
     * Lista de tipos de mensajes soportados por el CRM. Cualquier otro tipo
     * (reaction, unsupported, system, etc.) debe ser manejado explícitamente
     * o ignorado; no fabricar un "Mensaje multimedia" falso.
     */
    private function isSupportedMessageType(string $type): bool
    {
        return in_array($type, [
            'text',
            'image',
            'sticker',
            'document',
            'audio',
            'video',
            'location',
            'contacts',
        ], true);
    }

    /**
     * Detecta si un webhook entrante representa la eliminación de un mensaje
     * por parte del contacto desde su celular ("delete for everyone").
     *
     * Meta puede enviarlo como:
     *   1) type: "unsupported" con errors[].code = 131051 y original en context.id
     *   2) type: "revoke" con revoke.original_message_id
     */
    private function isMessageDeletionEvent(array $messageData): bool
    {
        $type = $messageData['type'] ?? null;

        if ($type === 'revoke') {
            return ! empty($messageData['revoke']['original_message_id']);
        }

        if ($type !== 'unsupported') {
            return false;
        }

        $errors = $messageData['errors'] ?? [];
        if (! is_array($errors)) {
            return true;
        }

        foreach ($errors as $error) {
            $code = $error['code'] ?? null;
            if ($code === 131051 || $code === '131051') {
                return true;
            }
        }

        // Si llega type=unsupported sin errors, igual asumimos delete para no
        // crear un mensaje fantasma.
        return true;
    }

    /**
     * Detecta el id original de un mensaje editado por el contacto desde su celular.
     * Devuelve null si el mensaje no parece ser una edición.
     *
     * Meta puede enviarlo con varias formas. Soportamos:
     *   1) context.edited === true (formato histórico)
     *   2) messages[].edited === true con original id en context.id
     *   3) type: "edit" con edit.original_message_id
     *   4) Mensaje text con context.id que apunta a un mensaje INBOUND ya
     *      existente en el CRM y con campo context.from == from (no es reply)
     */
    private function detectEditedMessageOriginalId(array $messageData, int $tenantId): ?string
    {
        $context = $messageData['context'] ?? [];
        $originalId = $context['id'] ?? null;

        if (! empty($context['edited']) && $originalId) {
            return $originalId;
        }

        if (! empty($messageData['edited']) && $originalId) {
            return $originalId;
        }

        $editOriginalId = $messageData['edit']['original_message_id'] ?? null;
        if (($messageData['type'] ?? null) === 'edit' && $editOriginalId) {
            return $editOriginalId;
        }

        // Último recurso: si llega un mensaje de texto con context.id apuntando
        // a un mensaje INBOUND existente del mismo remitente, es casi seguro una
        // edición (replies suelen venir con context.from distinto del id real del
        // mensaje referenciado, y Meta no genera "replies a sí mismo").
        if (($messageData['type'] ?? null) === 'text' && $originalId) {
            $from = $messageData['from'] ?? null;
            $contextFrom = $context['from'] ?? null;

            if ($from && $contextFrom && $from === $contextFrom) {
                $existing = Message::where('tenant_id', $tenantId)
                    ->where('external_id', $originalId)
                    ->where('direction', MessageDirection::INBOUND)
                    ->exists();

                if ($existing) {
                    return $originalId;
                }
            }
        }

        return null;
    }

    /**
     * @return array{content: string, type: string, media_id: string|null}
     */
    private function extractMessageData(array $messageData): array
    {
        $type = $messageData['type'] ?? 'unknown';

        $content = match ($type) {
            'text' => $messageData['text']['body'] ?? '',
            'edit' => $messageData['edit']['message']['text']['body']
                ?? $messageData['edit']['text']['body']
                ?? '',
            'image' => $messageData['image']['caption'] ?? '',
            'sticker' => '',
            'document' => $messageData['document']['filename'] ?? 'Documento',
            'audio' => '',
            'video' => $messageData['video']['caption'] ?? '',
            'location' => 'Ubicación compartida',
            'contacts' => 'Contacto compartido',
            default => '',
        };

        $mediaId = match ($type) {
            'image' => $messageData['image']['id'] ?? null,
            'sticker' => $messageData['sticker']['id'] ?? null,
            'document' => $messageData['document']['id'] ?? null,
            'audio' => $messageData['audio']['id'] ?? null,
            'video' => $messageData['video']['id'] ?? null,
            default => null,
        };

        $mappedType = match ($type) {
            'text' => 'text',
            'edit' => 'text',
            'image' => 'image',
            'sticker' => 'sticker',
            'document' => 'document',
            'audio' => 'audio',
            'video' => 'video',
            default => 'text',
        };

        return [
            'content' => $content,
            'type' => $mappedType,
            'media_id' => $mediaId,
        ];
    }

    /**
     * @return array{url: string, mime_type: string, filename: string}
     */
    private function downloadWhatsAppMedia(string $mediaId, string $accessToken, int $tenantId): array
    {

        $metaResponse = Http::withToken($accessToken)
            ->timeout(10)
            ->get("https://graph.facebook.com/{$this->graphVersion()}/{$mediaId}");

        if (! $metaResponse->successful()) {
            Log::error("Error obteniendo URL de media WhatsApp: {$metaResponse->body()}");

            return ['url' => '', 'mime_type' => '', 'filename' => ''];
        }

        $mediaUrl = $metaResponse->json('url');
        $mimeType = $metaResponse->json('mime_type', 'application/octet-stream');

        $fileResponse = Http::withToken($accessToken)->timeout(30)->get($mediaUrl);

        if (! $fileResponse->successful()) {
            Log::error("Error descargando media de WhatsApp: {$fileResponse->status()}");

            return ['url' => '', 'mime_type' => '', 'filename' => ''];
        }

        $extension = $this->mimeToExtension($mimeType);
        $filename = uniqid('wa_').'.'.$extension;
        $path = "messages/{$tenantId}/{$filename}";

        Storage::disk('public')->put($path, $fileResponse->body());

        return [
            'url' => '/storage/'.$path,
            'mime_type' => $mimeType,
            'filename' => $filename,
        ];
    }

    private function normalizePhoneForWhatsApp(string $phone): string
    {
        if (strpos($phone, '549') === 0) {
            return '54'.substr($phone, 3);
        }

        return $phone;
    }

    /**
     * @return array{to: string, recipient_type: string, business_phone_id: string, business_token: string}
     */
    private function resolveOutboundWhatsAppContext(Conversation $conversation): array
    {
        $channel = $conversation->channel;
        if (! $channel) {
            throw new \InvalidArgumentException('La conversación no tiene un canal asociado.');
        }

        $credentials = $this->resolveWhatsAppCredentials($channel);

        if ($conversation->isGroup()) {
            $group = $conversation->whatsappGroup;
            if (! $group || ! $group->group_id || ! $group->isActive()) {
                throw new \InvalidArgumentException('El grupo todavía se está creando en WhatsApp o ya no está activo.');
            }

            return [
                'to' => $group->group_id,
                'recipient_type' => 'group',
                ...$credentials,
            ];
        }

        $phone = $conversation->contact?->phone;
        if (! $phone) {
            throw new \InvalidArgumentException('La conversación no tiene un teléfono de contacto válido.');
        }

        return [
            'to' => $this->normalizePhoneForWhatsApp($phone),
            'recipient_type' => 'individual',
            ...$credentials,
        ];
    }

    /**
     * Marca el último mensaje entrante como leído en Meta (doble check azul).
     * Fail-safe: registra warning y no lanza si el canal no es WA, la config falla o Meta rechaza.
     */
    public function markIncomingAsReadOnMeta(Conversation $conversation, string $externalId): void
    {
        try {
            $channel = $conversation->channel;
            if (! $channel || $channel->type !== ChannelType::WHATSAPP || ! $channel->isActive()) {
                return;
            }

            $waConfig = $channel->whatsappConfig;
            if (! $waConfig || ! $waConfig->phone_number_id) {
                return;
            }

            $businessToken = $waConfig->getDecryptedToken();
            if (! $businessToken) {
                return;
            }

            $response = Http::withToken($businessToken)
                ->timeout(10)
                ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$waConfig->phone_number_id}/messages", [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $externalId,
                ]);

            if (! $response->successful()) {
                Log::warning('WhatsApp markAsRead falló', [
                    'conversation_id' => $conversation->id,
                    'external_id' => $externalId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsApp markAsRead excepción: '.$e->getMessage(), [
                'conversation_id' => $conversation->id,
                'external_id' => $externalId,
            ]);
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
            'video/3gpp' => '3gp',
            'audio/aac' => 'aac',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function createMessage(array $messageData): Message
    {
        $message = Message::create($messageData);

        // Si el contacto responde después de un mensaje saliente, necesariamente
        // abrió la conversación. Meta puede omitir el webhook `read` cuando el
        // contacto desactiva las confirmaciones de lectura; la respuesta es una
        // evidencia más fuerte y permite completar el estado sin inventarlo.
        if ($message->direction === MessageDirection::INBOUND) {
            $this->inferReadFromInboundReply($message);
        }

        if (isset($messageData['conversation_id'])) {
            $type = $messageData['message_type'] ?? 'text';
            $lastContent = match ($type) {
                'image' => '📷 '.($messageData['content'] ?: 'Imagen'),
                'sticker' => '🏷️ Sticker',
                'video' => '🎥 '.($messageData['content'] ?: 'Video'),
                'audio' => '🎵 Audio',
                'document' => '📄 '.($messageData['content'] ?: 'Documento'),
                default => $messageData['content'] ?? '',
            };

            $conversationUpdates = [
                'last_message_at' => $message->created_at,
                'last_message_content' => $lastContent,
            ];

            // Handoff: un eco saliente de un humano (respuesta desde el
            // WhatsApp Business App vía processSmbMessageEchoes) apaga el bot,
            // igual que los send*FromCRM. Sin esto, un INBOUND posterior podría
            // disparar la auto-respuesta pese a que ya intervino una persona.
            if ($message->direction === MessageDirection::OUTBOUND
                && $message->sender_type === SenderType::USER) {
                $conversationUpdates['ai_autoreply_enabled'] = false;
            }

            Conversation::where('id', $messageData['conversation_id'])
                ->update($conversationUpdates);
        }

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $messageData['tenant_id']));
        } catch (\Exception $e) {
            Log::error('Error broadcasting message: '.$e->getMessage());
        }

        $this->maybeDispatchAiReply($message);

        return $message;
    }

    private function inferReadFromInboundReply(Message $inboundMessage): void
    {
        $readAt = $inboundMessage->delivered_at ?? $inboundMessage->created_at;

        $unreadOutboundMessages = Message::query()
            ->where('conversation_id', $inboundMessage->conversation_id)
            ->where('direction', MessageDirection::OUTBOUND)
            ->whereNull('read_at')
            ->where('id', '<', $inboundMessage->id)
            ->get();

        foreach ($unreadOutboundMessages as $outboundMessage) {
            $outboundMessage->update([
                'delivered_at' => $outboundMessage->delivered_at ?? $readAt,
                'read_at' => $readAt,
            ]);

            try {
                broadcast(new MessageStatusUpdated($outboundMessage));
            } catch (\Exception $e) {
                Log::error('Error broadcasting inferred WhatsApp read status: '.$e->getMessage());
            }
        }
    }

    /**
     * Despacha la generación de respuesta automática de IA si corresponde:
     * mensaje entrante de texto del contacto, conversación con auto-respuesta
     * activa y tenant con config de IA habilitada. El job revalida y aplica el
     * gate BYOK (key propia) antes de responder.
     *
     * El delay + ShouldBeUnique del job agrupan ráfagas de mensajes
     * consecutivos en una sola respuesta.
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

        // Guard duro: un bot respondiendo en un grupo de venta es un riesgo
        // real. No alcanza con ai_autoreply_enabled=false al crear el grupo
        // (alguien podría reactivarlo); se corta acá sin importar el flag.
        if ($conversation->isGroup()) {
            return;
        }

        // Chequeo barato para no encolar jobs no-op en tenants sin IA activa.
        // El job hace la validación completa (incluye desencriptar la key).
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

    /**
     * Procesa el webhook smb_app_state_sync (coexistencia).
     * Sincroniza los contactos del WhatsApp Business App al CRM.
     *
     * Meta sólo define dos actions: `add` (que cubre alta Y edición) y `remove`,
     * que además llega sin los campos de nombre. Aceptamos variantes extra
     * (added/edit/update/delete…) por robustez, pero no son parte del contrato.
     *
     * Ojo: el objeto `contact` de Meta trae full_name, first_name y phone_number.
     * No incluye ningún id de usuario, así que el phone es la única clave estable.
     *
     * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
     *
     * @return int Contactos creados o actualizados en este lote. El caller lo usa
     *             para registrar que el sync realmente trajo datos.
     */
    public function processSmbAppStateSync(array $changeValue, int $tenantId): int
    {
        $upserted = 0;
        $stateSync = $changeValue['state_sync'] ?? [];

        // Acciones que significan crear/actualizar el contacto en el CRM.
        $upsertActions = ['add', 'added', 'edit', 'edited', 'update', 'updated'];

        // Acciones que significan que el contacto fue eliminado de la agenda.
        // No borramos el registro para preservar historial de conversaciones.
        $removeActions = ['remove', 'removed', 'delete', 'deleted'];

        foreach ($stateSync as $syncItem) {
            if (($syncItem['type'] ?? '') !== 'contact') {
                continue;
            }

            $contactData = $syncItem['contact'] ?? [];
            $this->logUnexpectedContactFields($contactData);
            $action = strtolower(trim($syncItem['action'] ?? 'add'));
            $phoneNumber = $contactData['phone_number'] ?? null;
            $bsuid = $contactData['user_id'] ?? null;
            $fullName = $contactData['full_name'] ?? $contactData['first_name'] ?? 'Sin nombre';

            if (! $phoneNumber && ! $bsuid) {
                Log::warning('smb_app_state_sync: contacto sin phone_number ni user_id, ignorado', [
                    'action' => $action,
                ]);

                continue;
            }

            if (in_array($action, $upsertActions, true)) {
                if ($phoneNumber) {
                    // Anti-duplicado: si antes llegó el mismo contacto sin phone (keyed por
                    // external_id/BSUID) y ahora llega con phone, actualizamos ese registro
                    // en vez de crear uno nuevo.
                    if ($bsuid) {
                        $existingByBsuid = Contact::where('tenant_id', $tenantId)
                            ->where('external_id', $bsuid)
                            ->whereNull('phone')
                            ->first();

                        if ($existingByBsuid) {
                            $existingByBsuid->update([
                                'phone' => $phoneNumber,
                                'name' => $fullName,
                            ]);
                            $upserted++;

                            continue;
                        }
                    }

                    Contact::updateOrCreate(
                        ['tenant_id' => $tenantId, 'phone' => $phoneNumber],
                        ['name' => $fullName, 'external_id' => $bsuid, 'source' => 'whatsapp']
                    );
                    $upserted++;
                } else {
                    // Sin phone_number (username activado o sin mensajes recientes).
                    // Anti-duplicado: si ya existe por external_id, actualizamos ese registro
                    // en vez de crear uno nuevo.
                    $existingByBsuid = Contact::where('tenant_id', $tenantId)
                        ->where('external_id', $bsuid)
                        ->first();

                    if ($existingByBsuid) {
                        $existingByBsuid->update(['name' => $fullName]);
                        $upserted++;

                        continue;
                    }

                    Contact::updateOrCreate(
                        ['tenant_id' => $tenantId, 'external_id' => $bsuid],
                        ['name' => $fullName, 'source' => 'whatsapp']
                    );
                    $upserted++;
                }
            } elseif (in_array($action, $removeActions, true)) {
                // Preservamos el contacto y su historial; solo lo logueamos.
                Log::info('smb_app_state_sync: contacto removido de WA Business App (no se elimina del CRM)', [
                    'tenant_id' => $tenantId,
                    'phone' => $phoneNumber,
                    'external_id' => $bsuid,
                    'name' => $fullName,
                ]);
            } else {
                Log::warning('smb_app_state_sync: action desconocida ignorada', [
                    'action' => $action,
                    'phone' => $phoneNumber,
                    'external_id' => $bsuid,
                ]);
            }
        }

        return $upserted;
    }

    /**
     * Campos que Meta documenta para el objeto `contact` de smb_app_state_sync.
     *
     * `user_id` (BSUID) no figura en el ejemplo de la doc de coexistencia pero sí
     * llega cuando el feature está habilitado, y ya lo consumimos más arriba.
     *
     * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users
     */
    private const KNOWN_SMB_CONTACT_FIELDS = [
        'full_name',
        'first_name',
        'phone_number',
        'user_id',
        'parent_user_id',
        'username',
    ];

    /**
     * Loguea los NOMBRES de campos no documentados que Meta mande en el objeto
     * `contact`.
     *
     * Motivo: la doc de Meta no expone ninguna foto de perfil del contacto — el
     * único `profile_picture_url` de la Graph API pertenece al perfil del NEGOCIO,
     * no al del cliente. Esta sonda existe para verificar ese negativo contra
     * tráfico real, porque Meta a veces agrega campos antes de documentarlos. Si
     * algún día aparece algo con forma de imagen, queda registrado acá.
     *
     * Sólo se loguean las CLAVES, nunca los valores: el contenido son datos
     * personales del contacto y no deben terminar en los logs.
     */
    private function logUnexpectedContactFields(array $contactData): void
    {
        if ($contactData === []) {
            return;
        }

        $unexpected = array_diff(array_keys($contactData), self::KNOWN_SMB_CONTACT_FIELDS);

        if ($unexpected === []) {
            return;
        }

        Log::info('smb_app_state_sync: campos no documentados en el contacto', [
            'fields' => array_values($unexpected),
        ]);
    }

    /**
     * Procesa mensajes enviados desde la app de WhatsApp Business (coexistencia).
     * Estos llegan como smb_message_echoes y son mensajes OUTBOUND que el negocio
     * envió desde la app, no desde el CRM.
     */
    public function processSmbMessageEchoes(array $webhookData): void
    {
        $value = $webhookData['value'] ?? null;
        if (! is_array($value)) {
            return;
        }

        $echoes = $value['message_echoes'] ?? [];
        if (empty($echoes)) {
            return;
        }

        $channel = $this->resolveChannelFromWebhook($value, 'smb_message_echoes');
        if (! $channel) {
            return;
        }

        $tenantId = $channel->tenant_id;

        foreach ($echoes as $echo) {
            $customerPhone = $echo['to'] ?? null;
            if (! $customerPhone) {
                continue;
            }

            $externalId = $echo['id'] ?? null;
            if ($externalId && Message::where('tenant_id', $tenantId)->where('external_id', $externalId)->exists()) {
                continue;
            }

            $echoType = $echo['type'] ?? 'unknown';
            if (! $this->isSupportedMessageType($echoType)) {
                Log::warning('smb_message_echoes: tipo no soportado, echo ignorado', [
                    'type' => $echoType,
                    'echo' => $echo,
                    'tenant_id' => $tenantId,
                ]);

                continue;
            }

            $contact = $this->findOrCreateContact(null, $customerPhone, $channel);
            $conversation = $this->findOrCreateConversation($contact, $channel);

            $extracted = $this->extractMessageData($echo);
            $mediaFields = [];
            if ($extracted['media_id'] && $extracted['type'] !== 'text') {
                $waConfig = $channel->whatsappConfig;
                if ($waConfig) {
                    $accessToken = Crypt::decryptString($waConfig->bussines_token);
                    $mediaFields = $this->downloadWhatsAppMedia(
                        $extracted['media_id'],
                        $accessToken,
                        $tenantId
                    );
                }
            }

            $this->createMessage([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'sender_type' => SenderType::USER,
                'sender_id' => $channel->user_id,
                'content' => $extracted['content'],
                'message_type' => $extracted['type'],
                'media_url' => $mediaFields['url'] ?? null,
                'media_mime_type' => $mediaFields['mime_type'] ?? null,
                'media_filename' => $mediaFields['filename'] ?? null,
                'direction' => MessageDirection::OUTBOUND,
                'external_id' => $externalId,
                'delivered_at' => $this->parseWebhookTimestamp($echo['timestamp'] ?? null),
            ]);
        }
    }

    /**
     * Procesa el webhook del paso 2 de coexistencia (sync_type=history).
     *
     * Shape real, confirmado contra la doc oficial de Meta (Onboard WhatsApp
     * Business app users) y contra payloads de producción:
     *   value.history[] = [{
     *     metadata: { phase, chunk_order, progress },
     *     threads: [{
     *       id: "<wa_id del contacto, SIN 9 en AR>",
     *       messages: [{ id, from, to?, timestamp, type, text: {...}, history_context: { status }, ... }],
     *     }],
     *   }]
     *
     * El teléfono del contacto sale de threads[].id, no de messages[].to: `to`
     * sólo viene poblado cuando el mensaje representa un smb_message_echo, no en
     * el resto del historial (documentado explícitamente por Meta).
     *
     * La dirección se determina comparando `from` contra el número del negocio
     * (metadata.display_phone_number del propio webhook, más phone_number/
     * display_phone_number de la config) normalizados a dígitos puros vía
     * normalizePhoneForWhatsApp, porque display_phone_number en DB puede venir
     * formateado ("+54 9 223 436-3047") mientras que `from` siempre llega en
     * dígitos puros sin el 9 argentino.
     *
     * media_placeholder no se descarta: se persiste como mensaje de texto
     * provisorio. Meta promete un webhook posterior con el mismo wamid y el
     * contenido real "only if the message was sent within the last two weeks";
     * ese caso se resuelve con un updateOrCreate por external_id (ver más abajo),
     * nunca con un create nuevo, porque external_id es unique global en messages.
     *
     * @return array{imported: int, progress: int, phase: int|null, error_code: int|null}
     */
    public function processHistorySync(array $value, Channel $channel): array
    {
        $historyChunks = $value['history'] ?? [];

        // "Messaging history not shared": Meta entrega un chunk sin threads y con
        // un error explícito en vez de contenido. No es un fallo de nuestro lado.
        $errorCode = data_get($value, 'history.0.error.code') ?? data_get($value, 'errors.0.code');

        if (empty($historyChunks)) {
            return ['imported' => 0, 'progress' => 0, 'phase' => null, 'error_code' => $errorCode];
        }

        $tenantId = $channel->tenant_id;
        $waConfig = $channel->whatsappConfig;
        $businessNumbers = array_filter(array_map(
            fn (?string $n) => $n ? $this->normalizePhoneForWhatsApp(preg_replace('/\D/', '', $n)) : null,
            [
                $waConfig?->phone_number,
                $waConfig?->display_phone_number,
                data_get($value, 'metadata.display_phone_number'),
            ]
        ));

        $imported = 0;
        $progress = 0;
        $phase = null;

        foreach ($historyChunks as $chunk) {
            $progress = max($progress, (int) ($chunk['metadata']['progress'] ?? 0));
            $phase = $chunk['metadata']['phase'] ?? $phase;

            foreach ($chunk['threads'] ?? [] as $thread) {
                $threadPhone = $thread['id'] ?? null;

                foreach ($thread['messages'] ?? [] as $historyMessage) {
                    // Sólo se cuenta el `create`: un `update` es un placeholder
                    // resuelto por un webhook posterior con el mismo wamid, y
                    // Meta reentrega contenido duplicado entre chunks del mismo
                    // sync. Contar ambos infla el número muy por encima de los
                    // mensajes que realmente terminan en el chat.
                    if ($this->importHistoryMessage($historyMessage, $threadPhone, $businessNumbers, $channel, $tenantId) === 'created') {
                        $imported++;
                    }
                }
            }
        }

        return ['imported' => $imported, 'progress' => $progress, 'phase' => $phase, 'error_code' => $errorCode];
    }

    /**
     * @return 'created'|'updated'|'skipped'
     */
    private function importHistoryMessage(
        array $historyMessage,
        ?string $threadPhone,
        array $businessNumbers,
        Channel $channel,
        int $tenantId
    ): string {
        $externalId = $historyMessage['id'] ?? null;
        $type = $historyMessage['type'] ?? 'unknown';

        $isPlaceholder = $type === 'media_placeholder';
        if (! $isPlaceholder && ! $this->isSupportedMessageType($type)) {
            // reaction/edit/revoke/button/errors: mismo criterio que
            // processIncomingMessage, se ignoran sin frenar el resto del batch.
            return 'skipped';
        }

        $from = $historyMessage['from'] ?? null;
        $fromNormalized = $from ? $this->normalizePhoneForWhatsApp(preg_replace('/\D/', '', $from)) : null;
        $isOutbound = $fromNormalized && in_array($fromNormalized, $businessNumbers, true);

        // El contacto siempre es el otro extremo del thread, nunca el negocio.
        $customerPhone = $isOutbound
            ? ($threadPhone ?? $historyMessage['to'] ?? null)
            : ($from ?? $threadPhone);

        if (! $customerPhone) {
            return 'skipped';
        }

        $existing = $externalId ? Message::where('external_id', $externalId)->first() : null;

        $contact = $this->findOrCreateContact(null, $customerPhone, $channel);
        $conversation = $this->findOrCreateConversation($contact, $channel);

        if ($isPlaceholder) {
            if ($existing) {
                // Ya se resolvió por un webhook de contenido posterior; nada que hacer.
                return 'skipped';
            }

            $this->createMessage([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'sender_type' => $isOutbound ? SenderType::USER : SenderType::CONTACT,
                'sender_id' => $isOutbound ? $channel->user_id : $contact->id,
                'content' => 'Multimedia no disponible',
                'message_type' => MessageType::Text,
                'direction' => $isOutbound ? MessageDirection::OUTBOUND : MessageDirection::INBOUND,
                'external_id' => $externalId,
                'delivered_at' => $this->parseWebhookTimestamp($historyMessage['timestamp'] ?? null),
            ]);

            return 'created';
        }

        $extracted = $this->extractMessageData($historyMessage);
        $status = strtoupper((string) ($historyMessage['history_context']['status'] ?? ''));

        $attributes = [
            'tenant_id' => $tenantId,
            'conversation_id' => $conversation->id,
            'sender_type' => $isOutbound ? SenderType::USER : SenderType::CONTACT,
            'sender_id' => $isOutbound ? $channel->user_id : $contact->id,
            'content' => $extracted['content'],
            'message_type' => $extracted['type'],
            'direction' => $isOutbound ? MessageDirection::OUTBOUND : MessageDirection::INBOUND,
            'external_id' => $externalId,
            'delivered_at' => $this->parseWebhookTimestamp($historyMessage['timestamp'] ?? null),
            'read_at' => $status === 'READ' ? $this->parseWebhookTimestamp($historyMessage['timestamp'] ?? null) : null,
        ];

        if ($existing) {
            // Resuelve un media_placeholder previo (mismo wamid) con el contenido
            // real que llega en un webhook history separado, dentro de las 2
            // semanas. external_id es unique global: nunca un create nuevo acá.
            $existing->update($attributes);

            return 'updated';
        }

        $this->createMessage($attributes);

        return 'created';
    }

    public function sendTextMessageFromCRM(Conversation $conversation, string $content, User $user): Message
    {
        ['to' => $to, 'recipient_type' => $recipientType, 'business_phone_id' => $businessPhoneId, 'business_token' => $businessToken] =
            $this->resolveOutboundWhatsAppContext($conversation);

        $response = Http::withToken($businessToken)
            ->timeout(10)
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$businessPhoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => $recipientType,
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $content,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Error enviando mensaje a WhatsApp: '.$response->body());
        }

        $externalId = $response->json('messages.0.id');

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => $content,
            'direction' => MessageDirection::OUTBOUND,
            'message_type' => MessageType::Text,
            'external_id' => $externalId,
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => $content,
            // Handoff: si un humano responde, el bot se apaga en esta conversación.
            'ai_autoreply_enabled' => false,
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting outbound message: '.$e->getMessage());
        }

        return $message;
    }

    /**
     * Envía un mensaje de texto generado por el sistema (auto-respuesta IA).
     * Igual que sendTextMessageFromCRM pero con sender_type SYSTEM y sin usuario,
     * y sin handoff (el bot no se apaga a sí mismo).
     */
    public function sendSystemTextMessageFromCRM(Conversation $conversation, string $content): Message
    {
        ['to' => $to, 'recipient_type' => $recipientType, 'business_phone_id' => $businessPhoneId, 'business_token' => $businessToken] =
            $this->resolveOutboundWhatsAppContext($conversation);

        // Timeout explícito: esta llamada corre en GenerateAiReplyJob (worker
        // de colas). Sin timeout, un Graph API colgado bloquearía al worker
        // indefinidamente y frenaría los jobs de auto-respuesta de otras
        // conversaciones/tenants. tries=1 en el job evita duplicar el envío.
        $response = Http::withToken($businessToken)
            ->timeout(10)
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$businessPhoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => $recipientType,
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $content,
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Error enviando mensaje de IA a WhatsApp: '.$response->body());
        }

        $externalId = $response->json('messages.0.id');

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::SYSTEM,
            'content' => $content,
            'direction' => MessageDirection::OUTBOUND,
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
            Log::error('Error broadcasting AI outbound message: '.$e->getMessage());
        }

        return $message;
    }

    /**
     * Procesa la eliminación de un mensaje enviada desde el celular del contacto
     * ("delete for everyone"). Soft-elimina el mensaje en el CRM y notifica por SSE.
     */
    private function handleIncomingMessageDeleted(?string $externalId, int $tenantId): void
    {
        if (! $externalId) {
            return;
        }

        $message = Message::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('external_id', $externalId)
            ->first();

        if (! $message) {
            Log::info('handleIncomingMessageDeleted: mensaje no encontrado en CRM', [
                'external_id' => $externalId,
                'tenant_id' => $tenantId,
            ]);

            return;
        }

        // Idempotencia: si Meta reenvía el evento, no volvemos a borrar ni broadcastear.
        if ($message->trashed()) {
            return;
        }

        $conversation = $message->conversation;
        $conversationId = $message->conversation_id;
        $message->delete();

        $conversation?->syncLastMessageSummary();

        try {
            broadcast(new MessageDeleted($message, $conversationId));
            broadcast(new TenantMessageReceived($message, $tenantId));
        } catch (\Exception $e) {
            Log::error('Error broadcasting message deleted from webhook: '.$e->getMessage());
        }
    }

    /**
     * Procesa la edición de un mensaje enviada desde el celular del contacto.
     * Actualiza el contenido del mensaje original en el CRM y notifica por SSE.
     */
    private function handleIncomingMessageEdited(array $messageData, string $originalExternalId, int $tenantId): ?Message
    {
        $existingMessage = Message::where('tenant_id', $tenantId)
            ->where('external_id', $originalExternalId)
            ->first();

        if (! $existingMessage) {
            Log::info('handleIncomingMessageEdited: mensaje original no encontrado en CRM', [
                'original_external_id' => $originalExternalId,
                'tenant_id' => $tenantId,
            ]);

            return null;
        }

        $extracted = $this->extractMessageData($messageData);

        $existingMessage->update([
            'original_content' => $existingMessage->original_content ?? $existingMessage->content,
            'content' => $extracted['content'],
            'edited_at' => now(),
        ]);

        $freshMessage = $existingMessage->fresh();

        $freshMessage?->conversation?->syncLastMessageSummary();

        try {
            broadcast(new MessageEdited($freshMessage));
            broadcast(new TenantMessageReceived($freshMessage, $tenantId));
        } catch (\Exception $e) {
            Log::error('Error broadcasting message edited from webhook: '.$e->getMessage());
        }

        return $freshMessage;
    }
}
