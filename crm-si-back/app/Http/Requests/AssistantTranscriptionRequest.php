<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Audio grabado en el panel del asistente para transcribir. Los formatos son
 * los que produce MediaRecorder según el navegador (webm/ogg en Chrome y
 * Firefox, mp4 en Safari); el contenedor webm/mp4 se detecta a veces como video.
 */
class AssistantTranscriptionRequest extends FormRequest
{
    private const MIME_TYPES = [
        'audio/webm', 'video/webm', 'audio/ogg', 'audio/mp4', 'video/mp4', 'audio/x-m4a',
        'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/aac',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('assistant.use') === true;
    }

    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                'max:'.(int) config('services.ai.assistant.transcription.max_kb', 10240),
                'mimetypes:'.implode(',', self::MIME_TYPES),
            ],
        ];
    }
}
