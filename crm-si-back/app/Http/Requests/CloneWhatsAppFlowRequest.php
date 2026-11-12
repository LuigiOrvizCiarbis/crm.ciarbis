<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CloneWhatsAppFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('flows.manage');
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
        ];
    }
}
