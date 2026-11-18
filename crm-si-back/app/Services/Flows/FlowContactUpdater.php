<?php

namespace App\Services\Flows;

use App\Enums\ContactFieldType;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\WhatsAppFlow;
use App\Rules\ValidContactCustomData;
use App\Services\Flows\Builder\FlowDefinitionCompiler;
use App\Services\Flows\Builder\FlowDefinitionExpander;
use App\Support\ContactCustomDataNormalizer;
use App\Support\RepeaterFieldSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Valida y guarda en el contacto las respuestas mapeadas de un formulario con
 * las reglas del CRM. Cada campo se evalúa por separado: uno inválido no
 * impide guardar los demás. Los mensajes los lee el cliente en WhatsApp.
 *
 * Un grupo repetible se valida fila por fila al enviarla y se guarda entero
 * (reemplazando la lista del contacto) recién al terminar el grupo, con todas
 * las filas del recorrido.
 */
class FlowContactUpdater
{
    private const NAME_MAX = 255;

    /**
     * Guarda las respuestas válidas y devuelve qué se guardó (etiquetas de las
     * preguntas) y qué no (response_key → mensaje de error).
     *
     * @param  array<string, mixed>  $answers  respuestas de la pantalla enviada
     * @param  array<string, mixed>|null  $context  todas las respuestas del recorrido hasta acá (por defecto, `$answers`)
     * @param  list<string>|null  $finishedGroups  grupos repetibles que se terminan con este envío; null: el formulario terminó
     * @return array{saved: list<string>, errors: array<string, string>}
     */
    public function apply(WhatsAppFlow $flow, Contact $contact, array $answers, ?array $context = null, ?array $finishedGroups = null): array
    {
        ['attributes' => $attributes, 'custom' => $custom, 'saved' => $saved, 'errors' => $errors]
            = $this->evaluate($flow, $contact, $answers, $context ?? $answers, $finishedGroups);

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
    private function evaluate(WhatsAppFlow $flow, Contact $contact, array $answers, array $context, ?array $finishedGroups): array
    {
        $result = ['attributes' => [], 'custom' => [], 'saved' => [], 'errors' => []];
        $tenantId = (int) $contact->tenant_id;

        $version = $flow->liveVersion();

        foreach ($version->fieldMappings as $mapping) {
            if (($mapping['kind'] ?? null) === FlowDefinitionCompiler::REPEATER_MAPPING) {
                $finished = $finishedGroups === null || in_array($mapping['response_key'] ?? null, $finishedGroups, true);
                $this->repeater($result, $version, $tenantId, $mapping, $answers, $context, $finished);

                continue;
            }

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
     * Valida las filas que trae esta pantalla y, si el grupo termina, guarda
     * la lista entera. Las filas salen de `$context` (el recorrido), así una
     * fila de un camino abandonado no se guarda.
     *
     * @param  array{attributes: array<string, string>, custom: array<string, mixed>, saved: list<string>, errors: array<string, string>}  $result
     */
    private function repeater(array &$result, FlowVersion $version, int $tenantId, array $mapping, array $answers, array $context, bool $finished): void
    {
        $key = (string) ($mapping['response_key'] ?? '');
        $fieldKey = substr((string) ($mapping['target'] ?? ''), strlen('custom_data.'));
        $positions = array_map('strval', (array) ($mapping['fields'] ?? []));
        $maxRows = (int) ($mapping['max_rows'] ?? 0);
        $field = ContactField::forTenant($tenantId)->firstWhere('key', $fieldKey);

        if ($field === null || $field->type !== ContactFieldType::Repeater) {
            $result['errors'][$key] = 'El campo «'.$fieldKey.'» ya no existe en el CRM.';

            return;
        }

        $subfields = collect($field->options['fields'] ?? [])->keyBy('key');
        $types = $subfields->map(fn (array $subfield) => (string) ($subfield['type'] ?? 'text'))->all();
        $labels = $version->questionLabels();

        $rowErrors = [];

        foreach (FlowRepeaterAnswers::rowsIn($key, $positions, $maxRows, $answers) as $row) {
            foreach ($positions as $position => $subKey) {
                $answerKey = FlowDefinitionExpander::rowKey($key, $row, (int) $position);
                $subfield = $subfields->get($subKey);
                $label = $labels[$answerKey] ?? $answerKey;
                $value = FlowRepeaterAnswers::convert($answers[$answerKey] ?? null, $types[$subKey] ?? 'text');

                if ($subfield === null) {
                    $rowErrors[$answerKey] = "{$label}: el dato ya no existe en el CRM.";
                } elseif ($value === null && ($subfield['is_required'] ?? false) && ($subfield['is_active'] ?? true)) {
                    $rowErrors[$answerKey] = "{$label} es obligatorio.";
                } elseif (($error = RepeaterFieldSchema::scalarErrors($value, $subfield)[0] ?? null) !== null) {
                    $rowErrors[$answerKey] = "{$label}: {$error}";
                }
            }
        }

        $result['errors'] += $rowErrors;

        if (! $finished || $rowErrors !== []) {
            return;
        }

        $rows = FlowRepeaterAnswers::rows($key, $positions, $maxRows, $context, $types);

        if ($rows === null) {
            return;
        }

        $error = RepeaterFieldSchema::valueErrors($rows, $field->options ?? [])[0] ?? null;

        if ($error !== null) {
            $result['errors'][$key] = "{$field->label}: {$error}";

            return;
        }

        $result['custom'][$fieldKey] = $rows;
        $result['saved'][] = $field->label;
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
        return $version->questionLabels()[$key] ?? $key;
    }
}
