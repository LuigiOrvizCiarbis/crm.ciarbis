<?php

namespace App\Services\Flows;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\WhatsAppFlowStatus;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppFlow;
use App\Services\AiTypingIndicatorService;
use App\Services\MessagingWindowService;
use App\Services\WhatsAppMessageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Envía un formulario como mensaje interactivo (requiere la ventana de 24 h).
 * Fuera de la ventana se usa una plantilla con botón FLOW.
 */
class WhatsAppFlowMessageService
{
    public function __construct(
        private readonly WhatsAppFlowSessionService $sessions,
        private readonly WhatsAppMessageService $messages,
        private readonly MessagingWindowService $window,
    ) {}

    /**
     * @param  array{body: string, cta: string, header?: ?string, footer?: ?string}  $options
     */
    public function send(Conversation $conversation, WhatsAppFlow $flow, array $options, ?User $sender): Message
    {
        $this->ensureSendable($conversation, $flow);
        $this->window->assertOpen($conversation);

        $context = $this->messages->resolveOutboundWhatsAppContext($conversation);
        $session = $this->sessions->open($flow, $conversation);
        $body = $options['body'];
        $cta = $options['cta'];
        $header = filled($options['header'] ?? null) ? $options['header'] : null;
        $footer = filled($options['footer'] ?? null) ? $options['footer'] : null;

        $interactive = [
            'type' => 'flow',
            'body' => ['text' => $body],
            'action' => ['name' => 'flow', 'parameters' => $this->parameters($flow, $conversation, $session->flow_token, $cta)],
        ];

        if ($header !== null) {
            $interactive['header'] = ['type' => 'text', 'text' => $header];
        }

        if ($footer !== null) {
            $interactive['footer'] = ['text' => $footer];
        }

        $response = Http::withToken($context['business_token'])
            ->timeout(10)
            ->post('https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0')."/{$context['business_phone_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => $context['recipient_type'],
                'to' => $context['to'],
                'type' => 'interactive',
                'interactive' => $interactive,
            ]);

        if (! $response->successful()) {
            $this->messages->throwIfWindowClosed($response);

            throw new RuntimeException('Error enviando formulario a WhatsApp: '.$response->body());
        }

        $content = "🧩 {$flow->name}\n{$body}";
        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => $sender ? SenderType::USER : SenderType::SYSTEM,
            'sender_id' => $sender?->id,
            'content' => $content,
            'message_type' => MessageType::Flow,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => $response->json('messages.0.id'),
            'interactive' => [
                'kind' => 'flow',
                'whatsapp_flow_id' => $flow->id,
                'flow_name' => $flow->name,
                'session_id' => $session->id,
                'body' => $body,
                'cta' => $cta,
                'header' => $header,
                'footer' => $footer,
            ],
        ]);

        $session->update(['message_id' => $message->id]);

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
            Log::error('Error broadcasting flow message: '.$e->getMessage());
        }

        return $message;
    }

    private function ensureSendable(Conversation $conversation, WhatsAppFlow $flow): void
    {
        if ($conversation->isGroup()) {
            throw new InvalidArgumentException('Los formularios no se pueden enviar a grupos.');
        }

        if (! $flow->status->isSendable()) {
            throw new InvalidArgumentException('El formulario no está disponible para enviar (estado '.$flow->status->value.').');
        }

        if ($flow->whatsapp_config_id !== $conversation->channel?->whatsapp_config_id) {
            throw new InvalidArgumentException('El formulario no pertenece a la cuenta de WhatsApp de la conversación.');
        }
    }

    private function parameters(WhatsAppFlow $flow, Conversation $conversation, string $flowToken, string $cta): array
    {
        $parameters = [
            'flow_message_version' => '3',
            'flow_token' => $flowToken,
            'flow_id' => $flow->meta_flow_id,
            'flow_cta' => $cta,
        ];

        if ($flow->usesEndpoint()) {
            $parameters['flow_action'] = 'data_exchange';
        } else {
            $screen = $flow->entryScreen() ?? 'FIRST_ENTRY_SCREEN';
            $parameters['flow_action'] = 'navigate';
            $parameters['flow_action_payload'] = ['screen' => $screen];
            $prefill = $this->sessions->prefillData($flow, $conversation->contact, $screen);

            if ($prefill !== []) {
                $parameters['flow_action_payload']['data'] = $prefill;
            }
        }

        // Un formulario publicado y vuelto a editar sigue mandando su última
        // versión publicada (mode por defecto); sólo un borrador puro va en draft.
        if ($flow->status === WhatsAppFlowStatus::Draft && ! $flow->hasPublishedVersion()) {
            $parameters['mode'] = 'draft';
        }

        return $parameters;
    }
}
