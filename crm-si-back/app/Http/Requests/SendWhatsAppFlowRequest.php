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
            'body' => ['required', 'string', 'max:1024'],
            'cta' => ['required', 'string', 'max:30'],
            'header' => ['nullable', 'string', 'max:60'],
            'footer' => ['nullable', 'string', 'max:60'],
        ];
    }
}
