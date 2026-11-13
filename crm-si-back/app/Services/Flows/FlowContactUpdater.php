<?php

namespace App\Services\Flows;

use App\Enums\ContactFieldType;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\WhatsAppFlow;
use App\Rules\ValidContactCustomData;
use App\Support\ContactCustomDataNormalizer;
use Illuminate\Support\Facades\Validator;

/**
 * Valida y guarda en el contacto las respuestas mapeadas de un formulario con
 * las reglas del CRM. Cada campo se evalúa por separado: uno inválido no
 * impide guardar los demás. Los mensajes los lee el cliente en WhatsApp.
 */
class FlowContactUpdater
{
    private const NAME_MAX = 255;

    /**
     * Guarda las respuestas válidas y devuelve qué se guardó (etiquetas de las
     * preguntas) y qué no (response_key → mensaje de error).
     *
     * @return array{saved: list<string>, errors: array<string, string>}
     */
    public function apply(WhatsAppFlow $flow, Contact $contact, array $answers): array
    {
        ['attributes' => $attributes, 'custom' => $custom, 'saved' => $saved, 'errors' => $errors] = $this->evaluate($flow, $contact, $answers);

        $contact->fill($attributes);

        if ($custom !== []) {
            $contact->custom_data = array_merge($contact->custom_data ?? [], $custom);
        }

        if ($contact->isDirty()) {
            $contact->save();
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * @return array{attributes: array<string, string>, custom: array<string, mixed>, saved: list<string>, errors: array<string, string>}
     */
    private function evaluate(WhatsAppFlow $flow, Contact $contact, array $answers): array
    {
        $result = ['attributes' => [], 'custom' => [], 'saved' => [], 'errors' => []];
        $tenantId = (int) $contact->tenant_id;

        $version = $flow->liveVersion();

        foreach ($version->fieldMappings as $mapping) {
            $key = (string) ($mapping['response_key'] ?? '');
            $target = (string) ($mapping['target'] ?? '');
            $value = data_get($answers, $key);

            if ($key === '' || $value === null || $value === '') {
                continue;
            }

            $label = $this->label($version, $key);
            $error = null;

            if ($target === 'contact.name') {
                if (is_scalar($value) && mb_strlen((string) $value) <= self::NAME_MAX) {
                    $result['attributes']['name'] = (string) $value;
                } else {
                    $error = "{$label}: máximo ".self::NAME_MAX.' caracteres.';
                }
            } elseif ($target === 'contact.email') {
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $result['attributes']['email'] = (string) $value;
                } else {
                    $error = "{$label}: ingresá un email válido.";
                }
            } elseif (str_starts_with($target, 'custom_data.')) {
                $fieldKey = substr($target, strlen('custom_data.'));
                [$normalized, $error] = $this->customField($contact, $tenantId, $fieldKey, $value, $label);

                if ($error === null) {
                    $result['custom'][$fieldKey] = $normalized;
                }
            } else {
                continue;
            }

            if ($error !== null) {
                $result['errors'][$key] = $error;
            } else {
                $result['saved'][] = $label;
            }
        }

        return $result;
    }

    /**
     * La regla del CRM (ValidContactCustomData) decide si el valor vale; el
     * mensaje se arma por tipo de campo para que lo entienda el cliente.
     *
     * @return array{0: mixed, 1: ?string} valor normalizado y error
     */
    private function customField(Contact $contact, int $tenantId, string $fieldKey, mixed $value, string $label): array
    {
        $field = ContactField::forTenant($tenantId)->firstWhere('key', $fieldKey);

        if ($field === null) {
            return [null, "{$label}: el campo ya no existe en el CRM."];
        }

        $normalized = ContactCustomDataNormalizer::normalize([$fieldKey => $value], $tenantId)[$fieldKey] ?? null;
        $validator = Validator::make(
            ['custom_data' => [$fieldKey => $normalized]],
            ['custom_data' => ['array', new ValidContactCustomData($contact->id, [$fieldKey], $tenantId)]],
        );

        if (! $validator->fails()) {
            return [$normalized, null];
        }

        if ($normalized === null || $normalized === '' || $normalized === []) {
            return [null, "{$label} es obligatorio."];
        }

        $formatValid = ! Validator::make(['value' => $normalized], ['value' => $field->type->valueRules($field->options)])->fails();

        if ($formatValid && $field->is_unique) {
            return [null, "{$label}: ya está registrado para otro contacto."];
        }

        return [null, "{$label}: ".$this->formatMessage($field->type)];
    }

    private function formatMessage(ContactFieldType $type): string
    {
        return match ($type) {
            ContactFieldType::Text => 'máximo 1000 caracteres.',
            ContactFieldType::Number, ContactFieldType::Currency => 'ingresá un número.',
            ContactFieldType::Date => 'ingresá una fecha válida.',
            ContactFieldType::Email => 'ingresá un email válido.',
            ContactFieldType::Url => 'ingresá una dirección web válida (https://…).',
            ContactFieldType::Phone => 'máximo 50 caracteres.',
            ContactFieldType::Select, ContactFieldType::MultiSelect => 'elegí una de las opciones.',
            default => 'valor inválido.',
        };
    }

    /** Etiqueta de la pregunta en el constructor; si no, la clave. */
    private function label(FlowVersion $version, string $key): string
    {
        foreach ($version->definition['screens'] ?? [] as $screen) {
            foreach ($screen['blocks'] ?? [] as $block) {
                if (($block['key'] ?? null) === $key && filled($block['label'] ?? null)) {
                    return (string) $block['label'];
                }
            }
        }

        return $key;
    }
}
