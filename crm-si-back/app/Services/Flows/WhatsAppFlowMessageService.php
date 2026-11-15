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
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppTemplate;
use App\Services\AiTypingIndicatorService;
use App\Services\MessagingWindowService;
use App\Services\Templates\TemplateParameterException;
use App\Services\Templates\TemplateParameterRenderer;
use App\Services\WhatsAppMessageService;
use App\Services\WhatsAppTemplateService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Envía un formulario: como mensaje interactivo con la ventana de 24 h
 * abierta (`send`), o con una de sus plantillas de apertura fuera de la
 * ventana (`sendTemplate`).
 */
class WhatsAppFlowMessageService
{
    public function __construct(
        private readonly WhatsAppFlowSessionService $sessions,
        private readonly WhatsAppMessageService $messages,
        private readonly MessagingWindowService $window,
        private readonly FlowOpeningTemplates $openingTemplates,
        private readonly TemplateParameterRenderer $parameters,
        private readonly WhatsAppTemplateService $templates,
    ) {}

    /**
     * Plantilla de apertura lista para enviar: la indicada o, sin indicar, la
     * predeterminada.
     *
     * @throws InvalidArgumentException si no abre el formulario o no está lista
     */
    public function openingTemplate(WhatsAppFlow $flow, ?int $templateId): WhatsAppTemplate
    {
        if ($templateId === null) {
            return $this->openingTemplates->effective($flow)
                ?? throw new InvalidArgumentException('El formulario no tiene una plantilla aprobada para enviarlo fuera de la ventana de 24 h.');
        }

        $template = $this->openingTemplates->linked($flow)->firstWhere('id', $templateId)
            ?? throw new InvalidArgumentException('Esa plantilla no abre este formulario.');

        return match ($this->openingTemplates->issue($flow, $template)) {
            FlowOpeningTemplates::ISSUE_NOT_APPROVED => throw new InvalidArgumentException("La plantilla «{$template->name}» no está aprobada por Meta."),
            FlowOpeningTemplates::ISSUE_PARAMETERS => throw new InvalidArgumentException("Completá las variables de la plantilla «{$template->name}» en la apertura del formulario."),
            default => $template,
        };
    }

    /**
     * Envía el formulario con una plantilla de apertura ya resuelta
     * (`openingTemplate` o `FlowOpeningTemplates::effective`) y sus variables
     * configuradas. La sesión del formulario la abre
     * `WhatsAppTemplateService::sendTemplateMessage` al ver el botón FLOW.
     *
     * @throws TemplateParameterException si una variable queda sin valor
     */
    public function sendTemplate(Conversation $conversation, WhatsAppFlow $flow, WhatsAppTemplate $template, ?User $sender): Message
    {
        $this->ensureSendable($conversation, $flow);

        $components = $this->parameters->render(
            $template,
            $flow->openingParameters($template->id),
            ['contact' => $conversation->contact?->toArray() ?? []],
            $flow->tenant_id,
        );

        return $this->templates->sendTemplateMessage($conversation, $template, $components, $sender);
    }

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
            'action' => ['name' => 'flow', 'parameters' => $this->parameters($flow, $session, $cta)],
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

        $this->sessions->activate($session, $message);

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

    /**
     * Los formularios del constructor se abren sin consultar al CRM, como
     * recomienda Meta: la primera pantalla y sus datos (precarga y lo ya
     * respondido) viajan en el envío. Los JSON con endpoint piden el arranque
     * al endpoint (`data_exchange`).
     */
    private function parameters(WhatsAppFlow $flow, WhatsAppFlowSession $session, string $cta): array
    {
        $parameters = [
            'flow_message_version' => '3',
            'flow_token' => $session->flow_token,
            'flow_id' => $flow->meta_flow_id,
            'flow_cta' => $cta,
        ];

        $version = $flow->liveVersion();

        if ($version->definition === null && $version->usesEndpoint()) {
            $parameters['flow_action'] = 'data_exchange';
        } else {
            $screen = $version->entryScreen() ?? 'FIRST_ENTRY_SCREEN';
            $parameters['flow_action'] = 'navigate';
            $parameters['flow_action_payload'] = ['screen' => $screen];
            $data = $this->sessions->screenData($session, $screen);

            if ($data !== []) {
                $parameters['flow_action_payload']['data'] = $data;
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
