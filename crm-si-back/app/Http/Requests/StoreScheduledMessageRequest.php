<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\MessageController;
use App\Services\ScheduledMessageService;
use Illuminate\Foundation\Http\FormRequest;

class StoreScheduledMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mismas reglas de adjuntos que el envío inmediato: el archivo se guarda
     * ahora y se entrega a Meta tal cual cuando llegue la hora.
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:text,image,audio'],
            'content' => ['required_if:type,text', 'nullable', 'string', 'max:'.ScheduledMessageService::MAX_CONTENT_LENGTH],
            'image' => ['required_if:type,image', 'image', 'max:10240'],
            'audio' => ['required_if:type,audio', 'file', 'mimetypes:'.implode(',', MessageController::ALLOWED_AUDIO_MIMES), 'max:16384'],
            'voice' => ['sometimes', 'boolean'],
            'scheduled_for' => ['required', 'date', 'after:now', 'before:+'.ScheduledMessageService::MAX_DAYS_AHEAD.' days'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_for.after' => 'Elegí una fecha y hora futura.',
            'scheduled_for.before' => 'Sólo se puede programar hasta '.ScheduledMessageService::MAX_DAYS_AHEAD.' días adelante.',
            'audio.mimetypes' => 'Este formato de audio no es compatible. Probá grabar de nuevo o adjuntar un MP3, OGG o M4A.',
        ];
    }
}
