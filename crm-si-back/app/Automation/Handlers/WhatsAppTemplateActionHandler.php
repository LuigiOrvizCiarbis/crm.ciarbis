<?php

namespace App\Automation\Handlers;

use App\Automation\AutomationContext;
use App\Automation\Contracts\ActionHandler;
use App\Automation\Exceptions\ActionSkippedException;
use App\Automation\Exceptions\AmbiguousDeliveryException;
use App\Automation\Handlers\Concerns\SendsWhatsAppAutomationMessages;
use App\Enums\ChannelType;
use App\Models\AutomationAction;
use App\Models\AutomationRun;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\WhatsAppTemplate;
use App\Services\Templates\TemplateParameterException;
use App\Services\Templates\TemplateParameterRenderer;
use App\Services\WhatsAppTemplateService;
use Illuminate\Http\Client\ConnectionException;

class WhatsAppTemplateActionHandler implements ActionHandler
{
    use SendsWhatsAppAutomationMessages;

    public function __construct(
        private AutomationContext $context,
        private WhatsAppTemplateService $templates,
        private TemplateParameterRenderer $parameters,
    ) {}

    public function type(): string
    {
        return 'whatsapp_template';
    }

    public function metadata(): array
    {
        return [
            'label' => 'Enviar template de WhatsApp',
            'config_fields' => ['channel_id', 'template_id', 'parameters'],
            'parameter_sources' => ['literal', 'field'],
        ];
    }

    public function validate(array $config, int $tenantId): array
    {
        $errors = [];
        $channel = Channel::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($config['channel_id'] ?? null);
        if (! $channel || $channel->type !== ChannelType::WHATSAPP) {
            $errors['channel_id'][] = 'Seleccioná un canal de WhatsApp del espacio.';
        }

        $template = WhatsAppTemplate::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($config['template_id'] ?? null);
        if (! $template) {
            $errors['template_id'][] = 'Seleccioná un template del espacio.';
        }
        if ($channel && $template && $channel->whatsapp_config_id !== $template->whatsapp_config_id) {
            $errors['template_id'][] = 'El template no pertenece a la cuenta del canal.';
        }

        $errors = array_merge_recursive($errors, $this->parameters->validateParameters($config['parameters'] ?? [], $tenantId));

        if ($template) {
            $errors = array_merge_recursive($errors, $this->parameters->validateAgainstTemplate($template, $config['parameters'] ?? []));
        }

        return $errors;
    }

    public function preview(AutomationAction $action, AutomationRun $run): array
    {
        [$channel, $template] = $this->resolveConfiguration($action, $run);
        $contact = $this->contact($run);
        $conversation = Conversation::where('contact_id', $contact->id)->where('channel_id', $channel->id)->first();

        return [
            'channel' => ['id' => $channel->id, 'name' => $channel->name, 'status' => $channel->status],
            'template' => ['id' => $template->id, 'name' => $template->name, 'status' => $template->status->value],
            'conversation' => $conversation ? ['id' => $conversation->id, 'will_create' => false] : ['id' => null, 'will_create' => true],
            'parameters' => $this->renderParameters($action, $run, $template),
        ];
    }

    public function execute(AutomationAction $action, AutomationRun $run): array
    {
        [$channel, $template] = $this->resolveConfiguration($action, $run);
        $contact = $this->contact($run);
        if (! $contact->phone) {
            throw new ActionSkippedException('contact_phone_missing');
        }
        if (! $channel->isActive()) {
            throw new ActionSkippedException('channel_inactive');
        }
        if (! $template->isApproved()) {
            throw new ActionSkippedException('template_not_approved');
        }
        if ($channel->branch_id && $contact->branch_id && $channel->branch_id !== $contact->branch_id) {
            throw new ActionSkippedException('incompatible_branch');
        }

        $conversation = $this->conversationFor($run, $contact, $channel);
        $components = $this->renderParameters($action, $run, $template);
        $this->throttleWhatsApp($channel);

        try {
            $message = $this->templates->sendSystemTemplateMessage($conversation, $template, $components);
        } catch (ConnectionException $exception) {
            throw new AmbiguousDeliveryException('meta_timeout_after_send', previous: $exception);
        } catch (\RuntimeException $exception) {
            $this->rethrowSendFailure($exception);
        }

        return ['message_id' => $message->id, 'external_id' => $message->external_id, 'conversation_id' => $conversation->id];
    }

    private function resolveConfiguration(AutomationAction $action, AutomationRun $run): array
    {
        $channel = Channel::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->with('whatsappConfig')->find($action->config['channel_id'] ?? null);
        $template = WhatsAppTemplate::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->find($action->config['template_id'] ?? null);
        if (! $channel || $channel->type !== ChannelType::WHATSAPP) {
            throw new ActionSkippedException('channel_not_found');
        }
        if (! $template || $template->whatsapp_config_id !== $channel->whatsapp_config_id) {
            throw new ActionSkippedException('template_not_found');
        }

        return [$channel, $template];
    }

    private function renderParameters(AutomationAction $action, AutomationRun $run, WhatsAppTemplate $template): array
    {
        try {
            return $this->parameters->render($template, $action->config['parameters'] ?? [], $this->context->forRun($run), $run->tenant_id);
        } catch (TemplateParameterException $exception) {
            throw new ActionSkippedException($exception->getMessage());
        }
    }
}
