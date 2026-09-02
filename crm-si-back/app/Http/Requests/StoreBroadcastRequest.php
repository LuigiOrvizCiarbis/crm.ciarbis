<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBroadcastRequest extends FormRequest
{
    private const RANGE_OPERATORS = ['between', 'greater_or_equal', 'less_or_equal'];

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
            'filters.excluded_tag_ids' => ['nullable', 'array'],
            'filters.excluded_tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'filters.custom_filters' => ['nullable', 'array', 'max:10'],
            'filters.custom_filters.*.field' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_]+$/'],
            'filters.custom_filters.*.operator' => ['required', Rule::in(['equals', 'not_equals', 'contains', 'between', 'greater_or_equal', 'less_or_equal'])],
            // `between` manda {from, to} en vez de un string: se relaja acá y
            // se valida la forma exacta por operador en withValidator(), donde
            // sí se puede mirar el operador del mismo índice del array.
            'filters.custom_filters.*.value' => ['required'],
            'filters.custom_filters.*.value.from' => ['nullable', 'string', 'max:255'],
            'filters.custom_filters.*.value.to' => ['nullable', 'string', 'max:255'],
            'launch' => ['required', Rule::in(['now', 'scheduled'])],
            'scheduled_at' => ['nullable', 'required_if:launch,scheduled', 'date', 'after:now'],
            'interval_seconds' => ['required', 'integer', Rule::in([0, 15, 30, 60, 120])],
            // Confirmaciones explícitas para envíos de riesgo o escala; ver
            // BroadcastCampaignController::store().
            'include_without_consent' => ['nullable', 'boolean'],
            'acknowledge_consent_risk' => ['nullable', 'boolean'],
            'acknowledge_audience_size' => ['nullable', 'boolean'],
            'acknowledge_messaging_limit' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            foreach ((array) $this->input('filters.custom_filters', []) as $index => $filter) {
                if (! is_array($filter)) {
                    continue;
                }
                $operator = $filter['operator'] ?? null;
                $value = $filter['value'] ?? null;

                // Solo `between` necesita ambos bordes en un objeto: pide un
                // rango con dos extremos. greater_or_equal/less_or_equal son
                // un único límite, así que su value es un string simple igual
                // que equals/not_equals/contains — mismo shape en rangeBounds()
                // de BroadcastAudienceService.
                if ($operator === 'between') {
                    if (! is_array($value) || (! isset($value['from']) && ! isset($value['to']))) {
                        $v->errors()->add(
                            "filters.custom_filters.{$index}.value",
                            'Un filtro de rango necesita al menos un límite (desde o hasta).',
                        );
                    }

                    continue;
                }

                if (! is_string($value) || $value === '') {
                    $v->errors()->add(
                        "filters.custom_filters.{$index}.value",
                        'El valor del filtro debe ser un texto.',
                    );
                }
            }
        });
    }
}
