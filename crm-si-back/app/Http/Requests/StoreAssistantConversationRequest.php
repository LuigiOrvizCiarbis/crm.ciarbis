<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssistantConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistant.use') === true;
    }

    public function rules(): array
    {
        return [
            'first_message' => ['required', 'string', 'max:2000'],
        ];
    }
}
