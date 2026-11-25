<?php

namespace App\Services;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Mensaje de sistema con botón de link (`cta_url`), imagen o texto, según lo
 * configurado. Lo usan el cierre de formularios y el link de pago de la
 * compra por WhatsApp; `interactive` identifica quién lo mandó. `deliver`
 * envía cualquier cuerpo de mensaje ya armado (catálogo, productos).
 */
class WhatsAppInteractiveSender
{
    public function __construct(
        private readonly WhatsAppMessageService $messages,
    ) {}

    /**
     * @param  array{header: ?string, footer: ?string, button_text: ?string, url: ?string}  $config
     * @param  array<string, mixed>  $interactive
     */
    public function send(Conversation $conversation, string $body, array $config, ?string $imageLink, array $interactive): Message
    {
        $header = $config['header'] ?? null;
        $footer = $config['footer'] ?? null;
        $buttonText = $config['button_text'] ?? null;
        $url = $config['url'] ?? null;

        if ($buttonText !== null && $url !== null) {
            $payload = ['type' => 'interactive', 'interactive' => [
                'type' => 'cta_url',
                ...($imageLink !== null
                    ? ['header' => ['type' => 'image', 'image' => ['link' => $imageLink]]]
                    : ($header !== null ? ['header' => ['type' => 'text', 'text' => $header]] : [])),
                'body' => ['text' => $body],
                ...($footer !== null ? ['footer' => ['text' => $footer]] : []),
                'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $buttonText, 'url' => $url]],
            ]];
        } elseif ($imageLink !== null) {
            // Sin botón, la imagen va como mensaje de imagen con el texto de epígrafe (también hasta 1024).
            $payload = ['type' => 'image', 'image' => ['link' => $imageLink, 'caption' => $body]];
        } else {
            $payload = ['type' => 'text', 'text' => ['body' => $body, 'preview_url' => true]];
        }

        // El chat muestra encabezado, pie y link como texto; el detalle queda en `interactive`.
        $content = implode("\n\n", array_filter([$header, $body, $footer, $buttonText !== null ? "🔗 {$buttonText}: {$url}" : null]));

        return $this->deliver($conversation, $payload, $content, [
            ...$interactive,
            'button' => $buttonText !== null ? ['text' => $buttonText, 'url' => $url] : null,
        ], $imageLink);
    }

    /**
     * Envía `$payload` (`type` y su contenido) y lo guarda como mensaje
     * saliente. Un envío de una persona apaga la IA de la conversación.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $interactive
     */
    public function deliver(Conversation $conversation, array $payload, string $content, array $interactive, ?string $imageLink = null, ?User $sender = null): Message
    {
        $context = $this->messages->resolveOutboundWhatsAppContext($conversation);

        $response = Http::withToken($context['business_token'])
            ->timeout(10)
            ->post('https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0')."/{$context['business_phone_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => $context['recipient_type'],
                'to' => $context['to'],
                ...$payload,
            ]);

        if (! $response->successful()) {
            $this->messages->throwIfWindowClosed($response);

            throw new RuntimeException('Meta rechazó el mensaje: '.$response->body());
        }

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => $sender ? SenderType::USER : SenderType::SYSTEM,
            'sender_id' => $sender?->id,
            'content' => $content,
            'message_type' => $imageLink !== null ? MessageType::Image : MessageType::Text,
            'media_url' => $imageLink,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => $response->json('messages.0.id'),
            'interactive' => $interactive,
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => $content,
            // Un envío humano hace handoff; una automatización no apaga el bot.
            ...($sender ? ['ai_autoreply_enabled' => false] : []),
        ]);

        if ($sender) {
            app(AiTypingIndicatorService::class)->stop($conversation->id);
        }

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting interactive message: '.$e->getMessage());
        }

        return $message;
    }
}
