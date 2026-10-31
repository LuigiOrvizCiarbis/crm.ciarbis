<?php

namespace App\Http\Requests;

use App\Support\TimezoneAliases;
use Illuminate\Foundation\Http\FormRequest;

class StoreAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('automations.manage');
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
            'name' => ['required', 'string', 'max:150'],
            'trigger_type' => ['required', 'string', 'max:80'],
            'trigger_config' => ['present', 'array'],
            'conditions' => ['nullable', 'array'],
            'timezone' => ['nullable', 'timezone:all'],
            'actions' => ['required', 'array', 'min:1'],
            'actions.*.type' => ['required', 'string', 'max:80'],
            'actions.*.config' => ['present', 'array'],
        ];
    }
}
