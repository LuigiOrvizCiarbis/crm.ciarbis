<?php

namespace App\Http\Requests;

use App\Support\TemplateHeaderMediaRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendTemplateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('templates.send');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'template_id' => 'required|exists:whatsapp_templates,id',
            'components' => 'nullable|array',
            'components.*.type' => 'sometimes|string',
            'components.*.parameters' => 'sometimes|array',
            ...TemplateHeaderMediaRules::rules(),
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'template_id.required' => 'El template es requerido',
            'template_id.exists' => 'El template seleccionado no existe',
            'components.array' => 'Los componentes deben ser un array',
            ...TemplateHeaderMediaRules::messages(),
        ];
    }
}
