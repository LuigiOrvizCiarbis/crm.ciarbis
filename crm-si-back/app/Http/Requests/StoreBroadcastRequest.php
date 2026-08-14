<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('templates.send') === true;
    }

    public function rules(): array
    {
        $tenantId = $this->user()->tenant_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'channel_id' => [
                'required',
                'integer',
                Rule::exists('channels', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'template_id' => [
                'required',
                'integer',
                Rule::exists('whatsapp_templates', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'components' => ['nullable', 'array'],
            'filters' => ['nullable', 'array'],
            'filters.pipeline_stage_id' => [
                'nullable',
                'integer',
                Rule::exists('pipeline_stages', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'filters.tag_ids' => ['nullable', 'array'],
            'filters.tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'filters.custom_filters' => ['nullable', 'array', 'max:10'],
            'filters.custom_filters.*.field' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_]+$/'],
            'filters.custom_filters.*.operator' => ['required', Rule::in(['equals', 'not_equals', 'contains'])],
            'filters.custom_filters.*.value' => ['required', 'string', 'max:255'],
            'launch' => ['required', Rule::in(['now', 'scheduled'])],
            'scheduled_at' => ['nullable', 'required_if:launch,scheduled', 'date', 'after:now'],
            'interval_seconds' => ['required', 'integer', Rule::in([0, 15, 30, 60, 120])],
        ];
    }
}
