<?php

namespace App\Http\Requests;

use App\Enums\ContactFieldType;
use App\Models\ContactField;
use App\Support\TimezoneAliases;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class BillingConfigRequest extends FormRequest
{
    /**
     * Estados que el motor de cobranzas entiende de forma literal (Fase 5 del
     * plan): billing:roll-cycle y las reglas provisionadas por
     * billing:provision comparan contra estos valores exactos. No son
     * vocabulario libre del tenant como el nombre del campo — el campo
     * Select puede tener choices adicionales, pero estos tres tienen que
     * estar.
     */
    private const REQUIRED_STATUS_CHOICES = ['al_dia', 'impago', 'en_prueba'];

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('billing.manage');
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('timezone')) {
            return;
        }

        $this->merge([
            'timezone' => TimezoneAliases::canonicalize($this->string('timezone')->toString()),
        ]);
    }

    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'due_date_field_key' => ['required', 'string', 'max:100'],
            'status_field_key' => ['required', 'string', 'max:100'],
            'overdue_cycles_field_key' => ['required', 'string', 'max:100'],
            'externally_managed_field_key' => ['nullable', 'string', 'max:100'],
            'cycle_unit' => ['required', Rule::in(['days', 'weeks', 'months'])],
            'cycle_length' => ['required', 'integer', 'min:1', 'max:24'],
            'timezone' => ['required', 'timezone:all'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:60'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $tenantId = $this->user()->tenant_id;
            $fields = ContactField::forTenant($tenantId)->keyBy('key');

            $this->validateFieldType($v, $fields, 'due_date_field_key', ContactFieldType::Date);
            $this->validateFieldType($v, $fields, 'overdue_cycles_field_key', ContactFieldType::Number);

            if ($this->filled('externally_managed_field_key')) {
                $this->validateFieldType($v, $fields, 'externally_managed_field_key', ContactFieldType::Boolean);
            }

            $statusKey = $this->string('status_field_key')->toString();
            $statusField = $fields->get($statusKey);
            if (! $statusField) {
                $v->errors()->add('status_field_key', 'El campo de estado no existe en este tenant.');

                return;
            }
            if ($statusField->type !== ContactFieldType::Select) {
                $v->errors()->add('status_field_key', 'El campo de estado debe ser de tipo Selección.');

                return;
            }
            $choices = is_array($statusField->options['choices'] ?? null) ? $statusField->options['choices'] : [];
            $missing = array_diff(self::REQUIRED_STATUS_CHOICES, $choices);
            if ($missing !== []) {
                $v->errors()->add(
                    'status_field_key',
                    'El campo de estado debe incluir las opciones: '.implode(', ', $missing).'.',
                );
            }
        });
    }

    /** @param  Collection<string, ContactField>  $fields */
    private function validateFieldType(Validator $v, $fields, string $inputKey, ContactFieldType $expectedType): void
    {
        $key = $this->string($inputKey)->toString();
        $field = $fields->get($key);

        if (! $field) {
            $v->errors()->add($inputKey, 'El campo no existe en este tenant.');

            return;
        }

        if ($field->type !== $expectedType) {
            $v->errors()->add($inputKey, "El campo debe ser de tipo {$expectedType->value}.");
        }
    }
}
