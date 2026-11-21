<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turno de chat en formato useChat. El front manda solo el último mensaje: el
 * historial lo carga el agente desde el hilo guardado.
 */
class AssistantChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assistant.use') === true;
    }

    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'string', 'size:36'],
            'messages' => ['required', 'array', 'min:1', 'max:1'],
            'messages.0.role' => ['required', 'in:user'],
            'messages.0.parts' => ['required', 'array', 'min:1'],
            'messages.0.parts.*.text' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
