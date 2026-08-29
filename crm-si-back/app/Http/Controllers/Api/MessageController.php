<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelType;
use App\Enums\MessageType;
use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Exceptions\MetaApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Http\Requests\UpdateMessageRequest;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Message;
use App\Services\InstagramMessageService;
use App\Services\MailMessageService;
use App\Services\MessengerMessageService;
use App\Services\WhatsAppMessageService;
use App\Services\VoiceTranscoder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\JsonResponse;

class MessageController extends Controller
{
    /**
     * Mimes que aceptamos para el campo `audio` del envío general (regla de
     * validación de `store`). Laravel detecta el mime por CONTENIDO, no por lo
     * que declara el navegador, así que además de los formatos "de escritorio"
     * (mp3/ogg) sumamos lo que produce grabar/adjuntar audio desde mobile:
     * Chrome/Android graba webm/opus, Safari/iOS graba mp4 (que PHP suele
     * detectar como video/mp4 o audio/x-m4a), y las notas de voz de WhatsApp
     * reenviadas desde iOS llegan como audio/x-m4a.
     * `video/*` está acá porque son contenedores sólo-audio que PHP marca
     * como video; no habilita subir video real, sólo pasa la validación —
     * `WhatsAppMessageService` sigue tratándolo como audio.
     */
    private const ALLOWED_AUDIO_MIMES = [
        'audio/aac',
        'audio/mpeg',
        'audio/mp3',
        'audio/ogg',
        'audio/mp4',
        'audio/amr',
        'audio/3gpp',
        'audio/webm',
        'video/webm',
        'audio/x-m4a',
        'audio/wav',
        'audio/x-wav',
        'video/mp4',
    ];

    public function __construct(
        private WhatsAppMessageService $messageService,
        private InstagramMessageService $instagramService,
        private MessengerMessageService $messengerService,
        private MailMessageService $mailService,
        private VoiceTranscoder $voiceTranscoder,
    ) {}

    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $messages = Message::query()
            ->with(['mailDetails', 'mailAttachments', 'interactions'])
            ->withTrashed()
            ->where('conversation_id', $conversation->id)
            ->whereNull('mail_parent_message_id')
            ->orderBy('delivered_at')
            ->paginate((int) $request->query('per_page', 50));

        return response()->json([
            'data' => $messages->items(),
            'meta' => [
                'total' => $messages->total(),
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'content' => 'required_unless:type,image,audio,mail,contacts|nullable|string',
            'content_html' => 'nullable|string|max:200000',
            'type' => 'required|string|in:text,image,audio,mail,contacts',
            'contact_ids' => 'required_if:type,contacts|array|min:1|max:10',
            'contact_ids.*' => 'integer|distinct',
            'image' => 'required_if:type,image|image|max:10240',
            'audio' => 'required_if:type,audio|file|mimetypes:'.implode(',', self::ALLOWED_AUDIO_MIMES).'|max:16384',
            'voice' => 'sometimes|boolean',
            'cc' => 'nullable|array|max:20',
            'cc.*' => 'required|email:rfc|max:255',
            'bcc' => 'nullable|array|max:20',
            'bcc.*' => 'required|email:rfc|max:255',
            'attachments' => 'nullable|array|max:10',
            'attachments.*' => 'file|max:10240',
        ], [
            'audio.mimetypes' => 'Este formato de audio no es compatible. Probá grabar de nuevo o adjuntar un MP3, OGG o M4A.',
        ]);

        $conversation = Conversation::query()
            ->with(['channel.whatsappConfig', 'channel.instagramConfig', 'channel.facebookConfig', 'channel.mailConfig', 'contact'])
            ->whereKey($data['conversation_id'])
            ->where('tenant_id', $request->user()->tenant_id)
            ->firstOrFail();

        $this->authorize('sendMessage', $conversation);

        $type = $data['type'] ?? 'text';
        $channelType = $conversation->channel?->type;
        $voice = $request->boolean('voice');
        if ($voice && ($type !== 'audio' || $channelType !== ChannelType::WHATSAPP)) {
            return response()->json(['message' => 'voice sólo está disponible para audios de WhatsApp.'], 422);
        }
        $tenantId = $request->user()->tenant_id;

        // El servicio de transporte se elige por el tipo de canal. Las firmas de
        // los métodos send*FromCRM son idénticas entre los cuatro servicios.
        //
        // match exhaustivo con default explícito: un canal sin transporte de
        // envío (Telegram, Web, Manual) debe cortar acá con un 422 claro,
        // no caer silenciosamente a WhatsApp.
        if ($type === 'mail' && $channelType !== ChannelType::MAIL) {
            return response()->json(['message' => 'El formato email sólo está disponible en canales de correo.'], 422);
        }

        if ($type === 'contacts') {
            if ($channelType !== ChannelType::WHATSAPP) {
                return response()->json(['message' => 'Compartir contactos sólo está disponible en canales de WhatsApp.'], 422);
            }
            $contacts = Contact::query()
                ->visibleTo($request->user())
                ->whereIn('id', $data['contact_ids'] ?? [])
                ->get();
            if ($contacts->count() !== count($data['contact_ids'] ?? [])) {
                return response()->json(['message' => 'Uno o más contactos no están disponibles para compartir.'], 422);
            }
            try {
                $message = $this->messageService->sendContactsMessageFromCRM(
                    $conversation,
                    $this->messageService->buildContactCards($contacts),
                    $request->user(),
                );
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\RuntimeException $e) {
                Log::warning('Error enviando contactos por WhatsApp', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
                return response()->json(['message' => 'No se pudieron enviar los contactos por WhatsApp.'], 422);
            }
            return response()->json(['data' => $message], 201);
        }

        if ($type === 'mail'
            && trim((string) ($data['content'] ?? '')) === ''
            && ! $request->hasFile('attachments')) {
            return response()->json(['message' => 'Escribí una respuesta o adjuntá un archivo.'], 422);
        }
        $service = match ($channelType) {
            ChannelType::WHATSAPP => $this->messageService,
            ChannelType::INSTAGRAM => $this->instagramService,
            ChannelType::FACEBOOK => $this->messengerService,
            ChannelType::MAIL => $this->mailService,
            default => null,
        };

        if (! $service) {
            return response()->json([
                'message' => 'Este canal no admite el envío de mensajes desde el CRM.',
            ], 422);
        }

        // Instagram sólo acepta ciertos formatos de audio (aac/m4a/wav/mp4).
        // La validación general acepta formatos de WhatsApp (ogg/amr) que IG
        // rechaza; los cortamos acá con un mensaje claro antes de llamar a Meta.
        if ($type === 'audio'
            && $channelType === ChannelType::INSTAGRAM
            && $request->hasFile('audio')) {
            $mime = $request->file('audio')->getMimeType();
            $allowed = ['audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/wav', 'audio/x-wav'];
            if (! in_array($mime, $allowed, true)) {
                return response()->json([
                    'message' => 'Instagram no admite este formato de audio. Usá AAC, M4A o WAV.',
                ], 422);
            }
        }

        try {
            if ($type === 'mail' && $channelType === ChannelType::MAIL) {
                $attachments = [];
                foreach ($request->file('attachments', []) as $file) {
                    $path = $file->store("messages/{$tenantId}", 'public');
                    $mime = $file->getMimeType() ?: 'application/octet-stream';
                    $attachments[] = [
                        'path' => Storage::disk('public')->path($path),
                        'url' => '/storage/'.$path,
                        'name' => $file->getClientOriginalName(),
                        'mime' => $mime,
                        'type' => match (true) {
                            str_starts_with($mime, 'image/') => MessageType::Image,
                            str_starts_with($mime, 'audio/') => MessageType::Audio,
                            str_starts_with($mime, 'video/') => MessageType::Video,
                            default => MessageType::Document,
                        },
                    ];
                }

                $message = $this->mailService->sendRichTextMessageFromCRM(
                    $conversation,
                    trim((string) ($data['content'] ?? '')),
                    $request->user(),
                    $data['content_html'] ?? null,
                    array_values(array_unique($data['cc'] ?? [])),
                    array_values(array_unique($data['bcc'] ?? [])),
                    $attachments,
                );
            } elseif ($type === 'image' && $request->hasFile('image')) {
                $file = $request->file('image');
                $path = $file->store("messages/{$tenantId}", 'public');

                $message = $service->sendImageMessageFromCRM(
                    $conversation,
                    $path,
                    '/storage/'.$path,
                    $file->getMimeType(),
                    $data['content'] ?? null,
                    $request->user()
                );
            } elseif ($type === 'audio' && $request->hasFile('audio')) {
                $file = $request->file('audio');
                $voicePath = null;
                try {
                    if ($voice) {
                        $voicePath = $this->voiceTranscoder->transcode($file->getRealPath());
                        $path = "messages/{$tenantId}/".uniqid('voice_', true).'.ogg';
                        Storage::disk('public')->put($path, file_get_contents($voicePath));
                    } else {
                        $path = $file->store("messages/{$tenantId}", 'public');
                    }

                    if ($channelType === ChannelType::WHATSAPP) {
                        $message = $this->messageService->sendAudioMessageFromCRM(
                            $conversation, $path, '/storage/'.$path,
                            $voice ? 'audio/ogg' : ($file->getMimeType() ?: 'audio/mpeg'),
                            $request->user(), $voice
                        );
                    } else {
                        $message = $service->sendAudioMessageFromCRM(
                            $conversation, $path, '/storage/'.$path,
                            $file->getMimeType() ?: 'audio/mpeg', $request->user()
                        );
                    }
                } finally {
                    if ($voicePath !== null && is_file($voicePath)) {
                        unlink($voicePath);
                    }
                }
            } else {
                $message = $service->sendTextMessageFromCRM(
                    $conversation,
                    $data['content'],
                    $request->user()
                );
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (MetaApiException $e) {
            $channelName = match ($e->channelType) {
                ChannelType::INSTAGRAM => 'Instagram',
                ChannelType::FACEBOOK => 'Facebook',
                default => 'WhatsApp',
            };

            $errorMessage = match ($e->reason) {
                MetaApiException::REASON_WINDOW_CLOSED => 'La ventana de 24 horas de '.$channelName.
                    ' expiró: el contacto debe escribir primero para poder responderle.',
                MetaApiException::REASON_TOKEN_INVALID => 'La conexión de '.$channelName.
                    ' expiró. Reconectá el canal desde Configuración para volver a enviar mensajes.',
                MetaApiException::REASON_UNSUPPORTED_MEDIA => 'El archivo adjunto no es compatible con '.
                    $channelName.'. Probá con otro formato.',
                MetaApiException::REASON_MISSING_PERMISSION => 'Faltan permisos en la conexión de '.
                    $channelName.'. Reconectá el canal desde Configuración.',
                default => 'No se pudo enviar el mensaje a '.$channelName.
                    '. Verificá la configuración del canal e inténtalo de nuevo.',
            };

            Log::warning('Error de Meta API al enviar mensaje', [
                'conversation_id' => $conversation->id,
                'tenant_id' => $request->user()->tenant_id,
                'channel_type' => $e->channelType->name,
                'reason' => $e->reason,
                'code' => $e->metaCode,
                'subcode' => $e->metaSubcode,
            ]);

            return response()->json(['message' => $errorMessage], 422);
        } catch (\RuntimeException $e) {
            // El mensaje de la excepción incluye el body crudo de Meta; detectamos
            // el code 190 (token expirado/revocado) para dar una instrucción accionable
            // al usuario en lugar de un genérico.
            $tokenExpired = str_contains($e->getMessage(), '"code":190')
                || str_contains($e->getMessage(), 'OAuthException');

            $logContext = [
                'conversation_id' => $conversation->id,
                'tenant_id' => $request->user()->tenant_id,
                'user_id' => $request->user()->id,
                'type' => $type,
                'token_expired' => $tokenExpired,
                'error' => $e->getMessage(),
            ];

            if ($tokenExpired) {
                Log::warning('Token de WhatsApp expirado o revocado al enviar mensaje', $logContext);
            } else {
                Log::error('No se pudo enviar el mensaje por WhatsApp', $logContext);
            }

            $errorMessage = $tokenExpired
                ? 'La conexión de WhatsApp expiró. Reconectá el canal desde Configuración para volver a enviar mensajes.'
                : 'No se pudo enviar el mensaje a WhatsApp. Verifica la configuración del canal e inténtalo de nuevo.';

            // 422: es un problema de configuración del canal (dependencia upstream),
            // no una caída de gateway. Así el front muestra el mensaje al usuario.
            return response()->json(['message' => $errorMessage], 422);
        }

        return response()->json(['data' => $message], 201);
    }

    public function saveSharedContact(Request $request, Message $message, int $index): JsonResponse
    {
        $this->authorize('view', $message);
        $cards = is_array($message->contacts) ? $message->contacts : [];
        $card = $cards[$index] ?? null;
        if ($message->message_type !== MessageType::Contacts || ! is_array($card)) {
            return response()->json(['message' => 'La tarjeta de contacto no existe.'], 404);
        }

        $validated = $request->validate(['contact_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $contact = null;
        if (! empty($validated['contact_id'])) {
            $contact = Contact::query()->whereKey($validated['contact_id'])->where('tenant_id', $user->tenant_id)->firstOrFail();
            $this->authorize('update', $contact);
        } else {
            $this->authorize('create', Contact::class);
        }

        $phone = $card['phones'][0]['phone'] ?? null;
        $email = $card['emails'][0]['email'] ?? null;
        $attributes = array_filter([
            'name' => $card['name']['formatted_name'] ?? 'Sin nombre',
            'phone' => $phone,
            'email' => $email,
        ], static fn ($value) => $value !== null && $value !== '');

        if ($contact) {
            $contact->update($attributes);
        } else {
            $contact = Contact::create([
                'tenant_id' => $user->tenant_id,
                'branch_id' => $message->conversation?->branch_id,
                'source' => 'whatsapp',
                ...$attributes,
            ]);
        }

        return response()->json(['data' => new ContactResource($contact->refresh())]);
    }

    public function update(UpdateMessageRequest $request, Message $message): JsonResponse
    {
        $this->authorize('update', $message);

        if ($message->original_content === null) {
            $message->original_content = $message->content;
        }

        $message->update([
            'content' => $request->validated('content'),
            'edited_at' => now(),
            'original_content' => $message->original_content,
        ]);
        $message->translations()->delete();

        try {
            broadcast(new MessageEdited($message->fresh()));
        } catch (\Exception $e) {
            Log::error('Error broadcasting message edited: '.$e->getMessage());
        }

        return response()->json(['data' => $message->fresh()]);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        $this->authorize('delete', $message);

        $conversationId = $message->conversation_id;
        $message->translations()->delete();
        $message->delete();

        try {
            broadcast(new MessageDeleted($message, $conversationId));
        } catch (\Exception $e) {
            Log::error('Error broadcasting message deleted: '.$e->getMessage());
        }

        return response()->json(['message' => 'Mensaje eliminado']);
    }
}
