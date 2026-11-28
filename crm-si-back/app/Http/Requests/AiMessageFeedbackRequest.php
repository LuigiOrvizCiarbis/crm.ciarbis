<?php

namespace App\Http\Requests;

use App\Models\AiMessageFeedback;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiMessageFeedbackRequest extends FormRequest
{
    // La autorización sobre el mensaje la hace el controller con MessagePolicy.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', Rule::in([AiMessageFeedback::UP, AiMessageFeedback::DOWN])],
        ];
    }
}
