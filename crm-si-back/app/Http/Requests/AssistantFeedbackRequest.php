<?php

namespace App\Http\Requests;

use App\Models\AssistantMessageFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssistantFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistant.use') === true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', Rule::in([AssistantMessageFeedback::UP, AssistantMessageFeedback::DOWN])],
        ];
    }
}
