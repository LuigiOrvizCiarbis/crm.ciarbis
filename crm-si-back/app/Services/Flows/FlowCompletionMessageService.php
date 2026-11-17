<?php

namespace App\Services\Flows;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppTemplate;
use App\Services\Templates\TemplateParameterException;
use App\Services\Templates\TemplateParameterRenderer;
use App\Services\WhatsAppMessageService;
use App\Services\WhatsAppTemplateService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cierre del formulario: lo manda el CRM en el chat apenas llega la respuesta.
 * Puede ser un texto (el cliente acaba de responder, así que la ventana de
 * 24 h está abierta y no hace falta plantilla; con botón va como mensaje
 * interactivo `cta_url`, sin botón como texto, y con imagen opcional) o una
 * plantilla aprobada con sus variables completadas con las respuestas, el
 * contacto o texto fijo.
 */
class FlowCompletionMessageService
{
    private const BODY_MAX = 1024;

    /** `{{clave}}` del cierre de texto: una respuesta o una variable configurada. */
    public const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    public function __construct(
        private readonly WhatsAppMessageService $messages,
        private readonly WhatsAppTemplateService $templates,
        private readonly TemplateParameterRenderer $parameters,
    ) {}

    /**
     * Un fallo no corta el procesamiento de la respuesta: se registra y sigue.
     *
     * @param  array<string, string>  $values  respuestas legibles por clave, para {{clave}} y `answers.<clave>`
     */
    public function send(WhatsAppFlow $flow, Conversation $conversation, array $values): ?Message
    {
        $config = $flow->completionMessage();

        if ($config === null) {
            return null;
        }

        try {
            return $config['type'] === 'template'
                ? $this->deliverTemplate($flow, $conversation, $config['template_id'], $config['parameters'], $values)
                : $this->deliverText($flow, $conversation, $config, $values);
        } catch (\Throwable $e) {
            Log::warning('Flow: no se pudo enviar el mensaje al terminar', [
                'whatsapp_flow_id' => $flow->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, string>  $values
     */
    private function deliverTemplate(WhatsAppFlow $flow, Conversation $conversation, int $templateId, array $parameters, array $values): ?Message
    {
        $template = WhatsAppTemplate::withoutGlobalScopes()
            ->where('tenant_id', $flow->tenant_id)
            ->where('whatsapp_config_id', $flow->whatsapp_config_id)
            ->find($templateId);

        // Con botón de formulario, cada respuesta le abriría otro al cliente.
        if ($template === null || ! $template->isApproved() || $template->flowButton() !== null) {
            Log::info('Flow: plantilla de cierre no disponible, no se envía', [
                'whatsapp_flow_id' => $flow->id,
                'template_id' => $templateId,
                'status' => $template?->status->value,
                'flow_button' => $template?->flowButton() !== null,
            ]);

            return null;
        }

        $components = $this->parameters->render(
            $template,
            $parameters,
            ['contact' => $conversation->contact?->toArray() ?? [], 'answers' => $values],
            $flow->tenant_id,
        );

        return $this->templates->sendSystemTemplateMessage($conversation, $template, $components);
    }

    /**
     * @param  array{body: string, button_text: ?string, url: ?string, image: ?array<string, mixed>, variables: list<array<string, mixed>>}  $config
     * @param  array<string, string>  $values
     */
    private function deliverText(WhatsAppFlow $flow, Conversation $conversation, array $config, array $values): ?Message
    {
        $body = mb_substr(trim(self::render($config['body'], [...$values, ...$this->variableValues($flow, $conversation, $config['variables'], $values)])), 0, self::BODY_MAX);

        return $body === ''
            ? null
            : $this->deliver($flow, $conversation, $body, $config['button_text'], $config['url'], $this->imageLink($flow, $config['image']));
    }

    /**
     * Valor de cada variable configurada del texto (respuesta, dato del contacto
     * ya actualizado o texto fijo). Una que queda sin valor ni respaldo va
     * vacía, como una respuesta que falta: el cierre sale igual.
     *
     * @param  list<array<string, mixed>>  $variables
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function variableValues(WhatsAppFlow $flow, Conversation $conversation, array $variables, array $values): array
    {
        $context = ['contact' => $conversation->contact?->toArray() ?? [], 'answers' => $values];
        $resolved = [];
        foreach ($variables as $variable) {
            try {
                $resolved[(string) $variable['name']] = $this->parameters->textValue($variable, $context, $flow->tenant_id);
            } catch (TemplateParameterException) {
                $resolved[(string) $variable['name']] = '';
            }
        }

        return $resolved;
    }

    /**
     * Una imagen que ya no está (archivo borrado, URL que dejó de ser pública)
     * no impide el cierre: el texto sale igual, sin imagen.
     *
     * @param  array<string, mixed>|null  $image
     */
    private function imageLink(WhatsAppFlow $flow, ?array $image): ?string
    {
        if ($image === null) {
            return null;
        }

        try {
            return $this->parameters->imageUrl($image, $flow->tenant_id);
        } catch (TemplateParameterException $e) {
            Log::warning('Flow: imagen del cierre no disponible, se manda sin imagen', [
                'whatsapp_flow_id' => $flow->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Reemplaza {{clave}} por su valor; una clave sin valor queda vacía.
     *
     * @param  array<string, string>  $values
     */
    public static function render(string $template, array $values): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            fn (array $match) => $values[$match[1]] ?? '',
            $template,
        );
    }

    private function deliver(WhatsAppFlow $flow, Conversation $conversation, string $body, ?string $buttonText, ?string $url, ?string $imageLink): Message
    {
        $context = $this->messages->resolveOutboundWhatsAppContext($conversation);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => $context['recipient_type'],
            'to' => $context['to'],
        ];

        if ($buttonText !== null && $url !== null) {
            $payload['type'] = 'interactive';
            $payload['interactive'] = [
                'type' => 'cta_url',
                ...($imageLink !== null ? ['header' => ['type' => 'image', 'image' => ['link' => $imageLink]]] : []),
                'body' => ['text' => $body],
                'action' => ['name' => 'cta_url', 'parameters' => ['display_text' => $buttonText, 'url' => $url]],
            ];
        } elseif ($imageLink !== null) {
            // Sin botón, la imagen va como mensaje de imagen con el texto de epígrafe (también hasta 1024).
            $payload['type'] = 'image';
            $payload['image'] = ['link' => $imageLink, 'caption' => $body];
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['body' => $body, 'preview_url' => true];
        }

        $response = Http::withToken($context['business_token'])
            ->timeout(10)
            ->post('https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0')."/{$context['business_phone_id']}/messages", $payload);

        if (! $response->successful()) {
            $this->messages->throwIfWindowClosed($response);

            throw new RuntimeException('Meta rechazó el mensaje: '.$response->body());
        }

        // El chat muestra el link como texto; el detalle del botón queda en `interactive`.
        $content = $buttonText !== null ? "{$body}\n\n🔗 {$buttonText}: {$url}" : $body;
        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::SYSTEM,
            'content' => $content,
            'message_type' => $imageLink !== null ? MessageType::Image : MessageType::Text,
            'media_url' => $imageLink,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => $response->json('messages.0.id'),
            'interactive' => [
                'kind' => 'flow_completion',
                'whatsapp_flow_id' => $flow->id,
                'button' => $buttonText !== null ? ['text' => $buttonText, 'url' => $url] : null,
            ],
        ]);

        $conversation->update(['last_message_at' => $message->created_at, 'last_message_content' => $content]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting flow completion message: '.$e->getMessage());
        }

        return $message;
    }
}
