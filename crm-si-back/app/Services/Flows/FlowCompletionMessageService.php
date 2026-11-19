<?php

namespace App\Services\Flows;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppTemplate;
use App\Services\Templates\TemplateParameterException;
use App\Services\Templates\TemplateParameterRenderer;
use App\Services\WhatsAppInteractiveSender;
use App\Services\WhatsAppTemplateService;
use Illuminate\Support\Facades\Log;

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
        private readonly WhatsAppInteractiveSender $sender,
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
     * @param  array{body: string, header: ?string, footer: ?string, button_text: ?string, url: ?string, image: ?array<string, mixed>, variables: list<array<string, mixed>>}  $config
     * @param  array<string, string>  $values
     */
    private function deliverText(WhatsAppFlow $flow, Conversation $conversation, array $config, array $values): ?Message
    {
        $body = mb_substr(trim(self::render($config['body'], [...$values, ...$this->variableValues($flow, $conversation, $config['variables'], $values)])), 0, self::BODY_MAX);

        return $body === ''
            ? null
            : $this->sender->send(
                $conversation,
                $body,
                $config,
                $this->imageLink($flow, $config['image']),
                ['kind' => 'flow_completion', 'whatsapp_flow_id' => $flow->id],
            );
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
}
