<?php

namespace App\Automation\Handlers;

use App\Automation\Contracts\TriggerHandler;
use App\Models\AutomationRule;
use Carbon\CarbonImmutable;

class DateTriggerHandler implements TriggerHandler
{
    public function type(): string
    {
        return 'date.reached';
    }

    public function metadata(): array
    {
        return [
            'label' => 'Fecha alcanzada',
            'subject' => 'contact|conversation',
            'kind' => 'date',
            'config_fields' => ['subject', 'field', 'offset_value', 'offset_unit', 'offset_direction', 'local_time', 'recurrence'],
            'defaults' => ['offset_value' => 0, 'offset_unit' => 'days', 'offset_direction' => 'after', 'local_time' => '09:00'],
        ];
    }

    public function validate(array $config): array
    {
        $errors = [];
        if (! in_array($config['subject'] ?? null, ['contact', 'conversation'], true)) {
            $errors['subject'][] = 'El sujeto debe ser contacto o conversación.';
        }
        if (empty($config['field'])) {
            $errors['field'][] = 'El campo fecha es obligatorio.';
        }
        if (($config['offset_value'] ?? 0) < 0) {
            $errors['offset_value'][] = 'El offset no puede ser negativo.';
        }
        if (! in_array($config['offset_unit'] ?? 'days', ['days', 'weeks'], true)) {
            $errors['offset_unit'][] = 'La unidad debe ser días o semanas.';
        }
        if (! in_array($config['offset_direction'] ?? 'after', ['before', 'after'], true)) {
            $errors['offset_direction'][] = 'La dirección no es válida.';
        }
        if (! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $config['local_time'] ?? '09:00')) {
            $errors['local_time'][] = 'La hora debe usar el formato HH:mm.';
        }

        $recurrence = $config['recurrence'] ?? null;
        if (is_array($recurrence) && ($recurrence['enabled'] ?? false)) {
            $every = $recurrence['every'] ?? null;
            if (! is_numeric($every) || (int) $every != $every || (int) $every < 1 || ! in_array($recurrence['unit'] ?? null, ['days', 'weeks'], true)) {
                $errors['recurrence'][] = 'La recurrencia requiere una frecuencia válida.';
            }
            if (empty($recurrence['max_occurrences']) && empty($recurrence['ends_at'])) {
                $errors['recurrence'][] = 'La recurrencia debe tener máximo de ejecuciones o fecha final.';
            }
            if (array_key_exists('max_occurrences', $recurrence) && $recurrence['max_occurrences'] !== null && $recurrence['max_occurrences'] !== '') {
                $max = $recurrence['max_occurrences'];
                if (! is_numeric($max) || (int) $max != $max || (int) $max < 1) {
                    $errors['recurrence'][] = 'El máximo de ejecuciones debe ser un entero positivo.';
                }
            }
            if (! empty($recurrence['ends_at'])) {
                try {
                    CarbonImmutable::parse((string) $recurrence['ends_at']);
                } catch (\Throwable) {
                    $errors['recurrence'][] = 'La fecha final de la recurrencia no es válida.';
                }
            }
        }

        return $errors;
    }

    public function matches(AutomationRule $rule, array $event): bool
    {
        return ($event['type'] ?? null) === $this->type()
            && ($event['subject_type'] ?? null) === ($rule->trigger_config['subject'] ?? null);
    }
}
