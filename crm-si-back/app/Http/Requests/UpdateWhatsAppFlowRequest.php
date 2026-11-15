<?php

namespace App\Http\Requests;

use App\Models\ContactField;
use App\Services\Templates\TemplateParameterRenderer;
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
            'opening' => ['sometimes', 'array'],
            'opening.default_template_id' => ['nullable', 'integer'],
            'opening.templates' => ['sometimes', 'array', 'max:50'],
            'opening.templates.*' => ['array'],
            ...$this->parameterRules('opening.templates.*.parameters', self::CONTACT_PATH),
            // null lo desactiva. Texto: límites de Meta, cuerpo 1024 y botón de link 20; la
            // imagen, con la forma del encabezado de plantilla (archivo del espacio o URL https).
            'completion_message' => ['sometimes', 'nullable', 'array'],
            'completion_message.type' => ['required_with:completion_message', Rule::in(['text', 'template'])],
            'completion_message.body' => ['exclude_unless:completion_message.type,text', 'required', 'string', 'max:1024'],
            'completion_message.button_text' => ['exclude_unless:completion_message.type,text', 'nullable', 'string', 'max:20', 'required_with:completion_message.url'],
            'completion_message.url' => ['exclude_unless:completion_message.type,text', 'nullable', 'url:https', 'max:2000', 'required_with:completion_message.button_text'],
            'completion_message.image' => ['exclude_unless:completion_message.type,text', 'nullable', 'array'],
            'completion_message.image.source' => ['exclude_unless:completion_message.type,text', 'required_with:completion_message.image', Rule::in(['media_asset', 'literal'])],
            'completion_message.image.media_asset_id' => ['exclude_unless:completion_message.type,text', 'exclude_unless:completion_message.image.source,media_asset', 'required', 'integer'],
            'completion_message.image.value' => ['exclude_unless:completion_message.type,text', 'exclude_unless:completion_message.image.source,literal', 'required', 'url:https', 'max:2000'],
            'completion_message.template_id' => ['exclude_unless:completion_message.type,template', 'required', 'integer'],
            ...$this->parameterRules('completion_message.parameters', self::CONTACT_OR_ANSWER_PATH, 'completion_message.type,template'),
        ];
    }

    /** Datos del contacto que puede citar una variable (como en las automatizaciones). */
    private const CONTACT_PATH = '/^contact\.(name|phone|email|custom_data\.[A-Za-z0-9_]+)$/';

    /** El cierre también puede citar las respuestas del formulario. */
    private const CONTACT_OR_ANSWER_PATH = '/^(contact\.(name|phone|email|custom_data\.[A-Za-z0-9_]+)|answers\.[A-Za-z0-9_]+)$/';

    /**
     * Forma de los parámetros de plantilla (la de la acción de automatización);
     * que cubran la plantilla se valida en el controlador, que la conoce.
     *
     * @return array<string, list<mixed>>
     */
    private function parameterRules(string $prefix, string $pathPattern, ?string $onlyWhen = null): array
    {
        $when = $onlyWhen !== null ? ["exclude_unless:{$onlyWhen}"] : [];

        return [
            $prefix => [...$when, 'present', 'array', 'max:20'],
            "{$prefix}.*" => [...$when, 'array'],
            "{$prefix}.*.component" => [...$when, 'nullable', Rule::in(['body', 'header'])],
            "{$prefix}.*.name" => [...$when, 'nullable', 'string', 'max:100'],
            "{$prefix}.*.source" => [...$when, 'required', Rule::in(TemplateParameterRenderer::SOURCES)],
            "{$prefix}.*.value" => [...$when, 'nullable', 'string', 'max:2000'],
            "{$prefix}.*.path" => [...$when, 'nullable', 'string', 'max:150', "regex:{$pathPattern}"],
            "{$prefix}.*.fallback" => [...$when, 'nullable', 'string', 'max:1024'],
            "{$prefix}.*.media_asset_id" => [...$when, 'nullable', 'integer'],
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
