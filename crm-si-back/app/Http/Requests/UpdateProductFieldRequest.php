<?php

namespace App\Http\Requests;

use App\Enums\ContactFieldType;
use App\Support\RepeaterFieldSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('product_fields.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'required', 'string', 'max:120'],
            'options' => ['sometimes', 'nullable', 'array'],
            'options.choices' => ['sometimes', 'nullable', 'array'],
            'options.choices.*' => ['string', 'max:120'],
            'options.currency' => ['nullable', 'string', Rule::in(ContactFieldType::currencies())],
            'options.fields' => ['nullable', 'array'],
            'options.min_items' => ['nullable', 'integer', 'between:0,100'],
            'options.max_items' => ['nullable', 'integer', 'between:0,100'],
            'is_required' => ['sometimes', 'boolean'],
            'is_unique' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $field = $this->route('product_field');

        $validator->after(function ($v) use ($field) {
            if (! $field) {
                return;
            }

            if ($this->has('type') && $this->input('type') !== $field->type->value) {
                $v->errors()->add('type', 'No se puede cambiar el tipo de un campo existente.');
            }

            if ($this->has('key') && $this->input('key') !== $field->key) {
                $v->errors()->add('key', 'No se puede cambiar la clave de un campo existente.');
            }

            if ($field->type === ContactFieldType::Repeater && ! $this->has('options')
                && ($this->has('is_required') ? $this->boolean('is_required') : $field->is_required)
                && (int) ($field->options['max_items'] ?? RepeaterFieldSchema::DEFAULT_MAX_ITEMS) === 0) {
                $v->errors()->add('options.max_items', 'Un repeater obligatorio debe permitir al menos una fila.');
            }
            if ($field->type === ContactFieldType::Repeater && $this->boolean('is_unique')) {
                $v->errors()->add('is_unique', 'Un repeater no puede tener valores únicos.');
            }

            if ($field->type->requiresOptions() && $this->has('options')) {
                if ($field->type === ContactFieldType::Repeater) {
                    $effectiveOptions = array_merge($field->options ?? [], (array) $this->input('options', []));
                    foreach (RepeaterFieldSchema::errors((array) $this->input('options', [])) as $error) {
                        $v->errors()->add('options', $error);
                    }
                    if (($this->has('is_required') ? $this->boolean('is_required') : $field->is_required)
                        && (int) ($effectiveOptions['max_items'] ?? RepeaterFieldSchema::DEFAULT_MAX_ITEMS) === 0) {
                        $v->errors()->add('options.max_items', 'Un repeater obligatorio debe permitir al menos una fila.');
                    }
                    $existing = collect($field->options['fields'] ?? [])->keyBy('key');
                    foreach ((array) $this->input('options.fields', []) as $nested) {
                        $nestedKey = is_array($nested) ? ($nested['key'] ?? null) : null;
                        if (is_string($nestedKey) && isset($existing[$nestedKey]) && ($nested['type'] ?? null) !== ($existing[$nestedKey]['type'] ?? null)) {
                            $v->errors()->add('options.fields', 'No se puede cambiar el tipo de un subcampo existente.');
                        }
                    }

                    return;
                }
                if ($field->type === ContactFieldType::Currency) {
                    return;
                }
                $choices = $this->input('options.choices');
                if (! is_array($choices) || count($choices) === 0) {
                    $v->errors()->add('options.choices', 'Debe proveer al menos una opción.');
                }
            }
        });
    }
}
