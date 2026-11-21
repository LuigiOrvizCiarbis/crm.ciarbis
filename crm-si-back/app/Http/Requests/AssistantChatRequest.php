<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Laravel\Ai\Vercel\Vercel;

/**
 * Turno de chat en formato useChat. El front manda solo el último mensaje: el
 * historial lo carga el agente desde el hilo guardado.
 *
 * Ese mensaje es del usuario (pregunta nueva) o del asistente con la respuesta
 * a una confirmación pendiente (crear una tarea, etc.), que reanuda el turno.
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
            'messages.0.role' => ['required', 'in:user,assistant'],
            'messages.0.parts' => ['required', 'array', 'min:1'],
            'messages.0.parts.*.text' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $message = $this->input('messages.0');
                if (! is_array($message) || ($message['role'] ?? null) !== 'assistant') {
                    return;
                }

                if (Vercel::approvalResponsesFrom($message) === []) {
                    $validator->errors()->add('messages.0', 'El mensaje del asistente debe traer una respuesta de confirmación.');
                }
            },
        ];
    }
}
