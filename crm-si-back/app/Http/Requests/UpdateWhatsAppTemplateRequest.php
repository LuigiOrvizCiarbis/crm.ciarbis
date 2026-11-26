<?php

namespace App\Http\Requests;

use App\Enums\TemplateCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Mismos componentes que al crear; el nombre y el idioma no se pueden cambiar
 * en Meta, y la categoría es opcional (no se envía si no cambia).
 */
class UpdateWhatsAppTemplateRequest extends CreateWhatsAppTemplateRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', Rule::in([TemplateCategory::Marketing->value, TemplateCategory::Utility->value])],
        ] + Arr::except(parent::rules(), ['name', 'language', 'category', 'parameter_format']);
    }

    protected function passedValidation(): void
    {
        // El editor sólo arma variables con nombre: una plantilla con {{1}} se
        // enviaría sin ejemplos y Meta la rechazaría.
        $texts = collect($this->validated('components', []))->pluck('text')->filter()->implode("\n");
        abort_if(preg_match('/\{\{\s*\d+\s*\}\}/', $texts) === 1, 422, 'Esta plantilla usa variables numeradas ({{1}}): editala en WhatsApp Manager.');

        parent::passedValidation();
    }
}
