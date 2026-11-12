<?php

namespace App\Http\Requests;

use App\Models\ContactField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWhatsAppFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('flows.manage');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:200'],
            'categories' => ['sometimes', 'array', 'min:1'],
            'categories.*' => ['string', Rule::in(StoreWhatsAppFlowRequest::CATEGORIES)],
            'builder_definition' => ['sometimes', 'array', 'prohibits:flow_json'],
            'flow_json' => ['sometimes', 'string', 'max:10485760', 'json'],
            'field_mappings' => ['sometimes', 'array', 'max:50'],
            'field_mappings.*.response_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_.]+$/'],
            'field_mappings.*.target' => ['required', 'string', 'max:150', 'regex:/^(contact\.name|contact\.email|custom_data\.[A-Za-z0-9_]+)$/'],
            'send_defaults' => ['sometimes', 'array'],
            'send_defaults.header' => ['nullable', 'string', 'max:60'],
            'send_defaults.body' => ['required_with:send_defaults', 'string', 'max:1024'],
            'send_defaults.footer' => ['nullable', 'string', 'max:60'],
            'send_defaults.cta' => ['required_with:send_defaults', 'string', 'max:30'],
        ];
    }

    protected function passedValidation(): void
    {
        $known = ContactField::forTenant((int) $this->user()->tenant_id)->pluck('key')->all();

        foreach ($this->validated('field_mappings', []) as $mapping) {
            if (! str_starts_with($mapping['target'], 'custom_data.')) {
                continue;
            }

            $key = substr($mapping['target'], strlen('custom_data.'));
            abort_unless(in_array($key, $known, true), 422, "El campo «{$key}» no existe.");
        }
    }
}
