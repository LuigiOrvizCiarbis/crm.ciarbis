<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SendWhatsAppFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('flows.send');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'whatsapp_flow_id' => ['required', 'integer'],
            // Con plantilla (ventana de 24 h cerrada) los textos salen de la plantilla.
            'template_id' => ['nullable', 'integer'],
            'body' => ['required_without:template_id', 'nullable', 'string', 'max:1024'],
            'cta' => ['required_without:template_id', 'nullable', 'string', 'max:30'],
            'header' => ['nullable', 'string', 'max:60'],
            'footer' => ['nullable', 'string', 'max:60'],
        ];
    }
}
