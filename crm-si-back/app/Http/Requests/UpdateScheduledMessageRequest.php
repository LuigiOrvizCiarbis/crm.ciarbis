<?php

namespace App\Http\Requests;

use App\Services\ScheduledMessageService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScheduledMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'content' => ['sometimes', 'nullable', 'string', 'max:'.ScheduledMessageService::MAX_CONTENT_LENGTH],
            'scheduled_for' => ['sometimes', 'required', 'date', 'after:now', 'before:+'.ScheduledMessageService::MAX_DAYS_AHEAD.' days'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_for.after' => 'Elegí una fecha y hora futura.',
            'scheduled_for.before' => 'Sólo se puede programar hasta '.ScheduledMessageService::MAX_DAYS_AHEAD.' días adelante.',
        ];
    }
}
