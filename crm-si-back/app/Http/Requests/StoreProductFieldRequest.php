<?php

namespace App\Http\Requests;

use App\Enums\ContactFieldType;
use App\Support\RepeaterFieldSchema;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductFieldRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::in(ContactFieldType::values())],
            'options' => ['nullable', 'array'],
            'options.choices' => ['nullable', 'array'],
            'options.currency' => ['nullable', 'string', Rule::in(ContactFieldType::currencies())],
            'options.choices.*' => ['string', 'max:120'],
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
        $validator->after(function ($v) {
            $type = ContactFieldType::tryFrom((string) $this->input('type'));
            if ($type?->requiresOptions()) {
                if ($type === ContactFieldType::Repeater) {
                    foreach (RepeaterFieldSchema::errors((array) $this->input('options', [])) as $error) {
                        $v->errors()->add('options', $error);
                    }
                    if ($this->boolean('is_required') && (int) $this->input('options.max_items', RepeaterFieldSchema::DEFAULT_MAX_ITEMS) === 0) {
                        $v->errors()->add('options.max_items', 'Un repeater obligatorio debe permitir al menos una fila.');
                    }
                    if ($this->boolean('is_unique')) {
                        $v->errors()->add('is_unique', 'Un repeater no puede tener valores únicos.');
                    }

                    return;
                }
                if ($type === ContactFieldType::Currency) {
                    // La divisa es opcional en el request: si no viene, el
                    // controller cae en DEFAULT_CURRENCY. Sólo se rechaza una
                    // divisa presente y no soportada, cosa que ya cubre la regla
                    // de options.currency.
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
