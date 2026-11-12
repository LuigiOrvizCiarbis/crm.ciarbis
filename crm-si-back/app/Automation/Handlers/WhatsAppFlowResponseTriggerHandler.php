<?php

namespace App\Automation\Handlers;

use App\Automation\Contracts\TriggerHandler;
use App\Models\AutomationRule;

/**
 * Se dispara cuando llega la respuesta de un formulario de WhatsApp. Las
 * condiciones pueden leer las respuestas en `event.response.<clave>`.
 */
class WhatsAppFlowResponseTriggerHandler implements TriggerHandler
{
    public function type(): string
    {
        return 'whatsapp_flow.response_received';
    }

    public function metadata(): array
    {
        return [
            'label' => 'Respuesta de formulario de WhatsApp',
            'subject' => 'conversation',
            'kind' => 'event',
            'config_fields' => ['whatsapp_flow_id'],
        ];
    }

    public function validate(array $config): array
    {
        if (! filter_var($config['whatsapp_flow_id'] ?? null, FILTER_VALIDATE_INT)) {
            return ['whatsapp_flow_id' => ['Seleccioná un formulario.']];
        }

        return [];
    }

    public function matches(AutomationRule $rule, array $event): bool
    {
        return ($event['type'] ?? null) === $this->type()
            && ($event['subject_type'] ?? null) === 'conversation'
            && (int) ($event['whatsapp_flow_id'] ?? 0) === (int) ($rule->trigger_config['whatsapp_flow_id'] ?? 0);
    }
}
