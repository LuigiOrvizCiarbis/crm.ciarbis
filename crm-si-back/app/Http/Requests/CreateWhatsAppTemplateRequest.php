<?php

namespace App\Http\Requests;

use App\Enums\TemplateCategory;
use App\Enums\WhatsAppFlowStatus;
use App\Models\WhatsAppFlow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWhatsAppTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('templates.create');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:20', 'regex:/^[a-z]{2,3}(?:_[A-Z]{2})?$/'],
            'category' => ['required', 'string', Rule::in([TemplateCategory::Marketing->value, TemplateCategory::Utility->value])],
            'parameter_format' => ['nullable', Rule::in(['named'])],
            'components' => ['required', 'array', 'min:1'],
            'components.*.type' => ['required', 'string', Rule::in(['HEADER', 'BODY', 'FOOTER', 'BUTTONS'])],
            'components.*.format' => ['nullable', 'string', Rule::in(['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'components.*.text' => ['nullable', 'string', 'max:1024'],
            'components.*.example' => ['nullable', 'array'],
            'components.*.example.header_handle' => ['nullable', 'array', 'max:1'],
            'components.*.example.body_text_named_params' => ['nullable', 'array'],
            'components.*.example.body_text_named_params.*.param_name' => ['required_with:components.*.example.body_text_named_params', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*$/'],
            'components.*.example.body_text_named_params.*.example' => ['required_with:components.*.example.body_text_named_params', 'string', 'max:1024'],
            'components.*.buttons' => ['nullable', 'array', 'max:3'],
            'components.*.buttons.*.type' => ['required', 'string', Rule::in(['QUICK_REPLY', 'URL', 'PHONE_NUMBER', 'FLOW'])],
            'components.*.buttons.*.text' => ['required', 'string', 'max:25'],
            'components.*.buttons.*.url' => ['nullable', 'url', 'max:2000'],
            'components.*.buttons.*.phone_number' => ['nullable', 'string', 'max:20'],
            'components.*.buttons.*.flow_id' => ['nullable', 'string', 'max:64'],
            'components.*.buttons.*.flow_action' => ['nullable', Rule::in(['navigate', 'data_exchange'])],
            'components.*.buttons.*.navigate_screen' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function passedValidation(): void
    {
        $components = collect($this->validated('components', []));
        $body = $components->firstWhere('type', 'BODY');
        abort_if($body === null || blank($body['text'] ?? null), 422, 'El cuerpo de la plantilla es obligatorio.');
        $this->validateFlowButtons($components->firstWhere('type', 'BUTTONS')['buttons'] ?? []);

        $bodyText = (string) $body['text'];
        preg_match_all('/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/', $bodyText, $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        if ($names === []) {
            return;
        }

        $examples = collect($body['example']['body_text_named_params'] ?? [])->keyBy('param_name');
        foreach ($names as $name) {
            if (blank($examples->get($name)['example'] ?? null)) {
                abort(422, "Falta el ejemplo para la variable {{$name}}.");
            }
        }
    }

    /**
     * Meta admite un solo botón FLOW por plantilla, y tiene que apuntar a un
     * formulario de la misma cuenta que alguna vez se haya publicado.
     */
    private function validateFlowButtons(array $buttons): void
    {
        $flowButtons = array_values(array_filter($buttons, fn ($button) => ($button['type'] ?? null) === 'FLOW'));
        abort_if(count($flowButtons) > 1, 422, 'Una plantilla admite un solo botón de formulario.');

        if ($flowButtons === []) {
            return;
        }

        $flowId = $flowButtons[0]['flow_id'] ?? null;
        $flow = $flowId === null ? null : WhatsAppFlow::where('whatsapp_config_id', $this->route('channel')?->whatsapp_config_id)
            ->where('meta_flow_id', $flowId)
            ->first();

        $usable = $flow !== null
            && $flow->hasPublishedVersion()
            && ! in_array($flow->status, [WhatsAppFlowStatus::Deprecated, WhatsAppFlowStatus::Blocked], true);

        abort_unless($usable, 422, 'El botón de formulario necesita un formulario publicado de esta cuenta.');
    }
}
