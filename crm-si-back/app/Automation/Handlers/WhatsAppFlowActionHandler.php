<?php

namespace App\Automation\Handlers;

use App\Automation\AutomationContext;
use App\Automation\Contracts\ActionHandler;
use App\Automation\Exceptions\ActionSkippedException;
use App\Automation\Exceptions\AmbiguousDeliveryException;
use App\Automation\Handlers\Concerns\SendsWhatsAppAutomationMessages;
use App\Enums\ChannelType;
use App\Exceptions\MetaApiException;
use App\Models\AutomationAction;
use App\Models\AutomationRun;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\WhatsAppFlow;
use App\Services\Flows\FlowOpeningTemplates;
use App\Services\Flows\WhatsAppFlowMessageService;
use App\Services\MessagingWindowService;
use App\Services\Templates\TemplateParameterException;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;

/**
 * Envía un formulario de WhatsApp: como mensaje interactivo con la ventana de
 * 24 h abierta, o con su plantilla de apertura predeterminada si está
 * cerrada. Sin plantilla lista, con la ventana cerrada la acción se saltea.
 */
class WhatsAppFlowActionHandler implements ActionHandler
{
    use SendsWhatsAppAutomationMessages;

    public function __construct(
        private AutomationContext $context,
        private WhatsAppFlowMessageService $flowMessages,
        private MessagingWindowService $window,
        private FlowOpeningTemplates $openingTemplates,
    ) {}

    public function type(): string
    {
        return 'send_whatsapp_flow';
    }

    public function metadata(): array
    {
        return [
            'label' => 'Enviar formulario de WhatsApp',
            'config_fields' => ['channel_id', 'whatsapp_flow_id', 'body', 'cta'],
        ];
    }

    public function validate(array $config, int $tenantId): array
    {
        $errors = [];
        $channel = Channel::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($config['channel_id'] ?? null);
        if (! $channel || $channel->type !== ChannelType::WHATSAPP) {
            $errors['channel_id'][] = 'Seleccioná un canal de WhatsApp del espacio.';
        }

        $flow = WhatsAppFlow::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($config['whatsapp_flow_id'] ?? null);
        if (! $flow || ($channel && $channel->whatsapp_config_id !== $flow->whatsapp_config_id)) {
            $errors['whatsapp_flow_id'][] = 'Seleccioná un formulario de la cuenta del canal.';
        }

        if (! $this->textWithin($config['body'] ?? null, 1024)) {
            $errors['body'][] = 'El texto del mensaje es obligatorio.';
        }

        if (! $this->textWithin($config['cta'] ?? null, 30)) {
            $errors['cta'][] = 'El texto del botón es obligatorio (máx. 30).';
        }

        return $errors;
    }

    public function preview(AutomationAction $action, AutomationRun $run): array
    {
        [$channel, $flow] = $this->resolveConfiguration($action, $run);
        $contact = $this->contact($run);
        $conversation = Conversation::where('contact_id', $contact->id)->where('channel_id', $channel->id)->first();

        return [
            'channel' => ['id' => $channel->id, 'name' => $channel->name, 'status' => $channel->status],
            'flow' => ['id' => $flow->id, 'name' => $flow->name, 'status' => $flow->status->value],
            'conversation' => $conversation ? ['id' => $conversation->id, 'will_create' => false] : ['id' => null, 'will_create' => true],
            'body' => $action->config['body'] ?? null,
            'cta' => $action->config['cta'] ?? null,
            // Fuera de la ventana de 24 h se manda esta plantilla.
            'opening_template' => ($template = $this->openingTemplates->effective($flow)) ? ['id' => $template->id, 'name' => $template->name] : null,
        ];
    }

    public function execute(AutomationAction $action, AutomationRun $run): array
    {
        [$channel, $flow] = $this->resolveConfiguration($action, $run);
        $contact = $this->contact($run);
        if (! $contact->phone) {
            throw new ActionSkippedException('contact_phone_missing');
        }
        if (! $channel->isActive()) {
            throw new ActionSkippedException('channel_inactive');
        }
        if (! $flow->status->isSendable()) {
            throw new ActionSkippedException('flow_not_sendable');
        }
        if ($channel->branch_id && $contact->branch_id && $channel->branch_id !== $contact->branch_id) {
            throw new ActionSkippedException('incompatible_branch');
        }

        $conversation = $this->conversationFor($run, $contact, $channel);
        $windowOpen = $this->window->isOpen($conversation);
        $template = $windowOpen ? null : $this->openingTemplates->effective($flow);
        if (! $windowOpen && $template === null) {
            throw new ActionSkippedException('messaging_window_closed');
        }
        $this->throttleWhatsApp($channel);

        try {
            $message = $template === null
                ? $this->flowMessages->send($conversation, $flow, [
                    'body' => (string) $action->config['body'],
                    'cta' => (string) $action->config['cta'],
                ], null)
                : $this->flowMessages->sendTemplate($conversation, $flow, $template, null);
        } catch (MetaApiException) {
            throw new ActionSkippedException('messaging_window_closed');
        } catch (InvalidArgumentException $exception) {
            throw new ActionSkippedException($exception->getMessage());
        } catch (TemplateParameterException $exception) {
            throw new ActionSkippedException($exception->getMessage());
        } catch (ConnectionException $exception) {
            throw new AmbiguousDeliveryException('meta_timeout_after_send', previous: $exception);
        } catch (\RuntimeException $exception) {
            $this->rethrowSendFailure($exception);
        }

        return ['message_id' => $message->id, 'external_id' => $message->external_id, 'conversation_id' => $conversation->id];
    }

    /** @return array{0: Channel, 1: WhatsAppFlow} */
    private function resolveConfiguration(AutomationAction $action, AutomationRun $run): array
    {
        $channel = Channel::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->with('whatsappConfig')->find($action->config['channel_id'] ?? null);
        $flow = WhatsAppFlow::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->find($action->config['whatsapp_flow_id'] ?? null);
        if (! $channel || $channel->type !== ChannelType::WHATSAPP) {
            throw new ActionSkippedException('channel_not_found');
        }
        if (! $flow || $flow->whatsapp_config_id !== $channel->whatsapp_config_id) {
            throw new ActionSkippedException('flow_not_found');
        }

        return [$channel, $flow];
    }

    private function textWithin(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max;
    }
}
