<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Laravel\Ai\Vercel\Vercel;

/**
 * Turno de chat en formato useChat. El front manda solo el último mensaje: el
 * historial lo carga el agente desde el hilo guardado.
 *
 * Ese mensaje es del usuario (una pregunta de texto) o del asistente con la
 * respuesta a una confirmación pendiente (crear una tarea, etc.), que reanuda
 * el turno pausado.
 */
class AssistantChatRequest extends FormRequest
{
    private const MAX_ASSISTANT_PARTS = 20;

    public function authorize(): bool
    {
        return $this->user()?->can('assistant.use') === true;
    }

    /**
     * Del mensaje del asistente solo sirven las partes de herramientas (y de
     * ellas, lo que identifica la decisión): el texto y el razonamiento ya
     * están guardados y pueden superar cualquier tope razonable.
     */
    protected function prepareForValidation(): void
    {
        $message = $this->input('messages.0');
        if (! is_array($message) || ($message['role'] ?? null) !== 'assistant' || ! is_array($message['parts'] ?? null)) {
            return;
        }

        $toolParts = array_values(array_filter(
            $message['parts'],
            fn ($part) => is_array($part) && is_string($part['type'] ?? null) && str_starts_with($part['type'], 'tool-'),
        ));

        $this->merge(['messages' => [[
            'id' => $message['id'] ?? null,
            'role' => 'assistant',
            'parts' => array_map(fn (array $part) => array_intersect_key(
                $part,
                array_flip(['type', 'toolCallId', 'state', 'approval']),
            ), $toolParts),
        ]]]);
    }

    public function rules(): array
    {
        $base = [
            'conversation_id' => ['required', 'string', 'size:36'],
            'messages' => ['required', 'array', 'size:1'],
            'messages.0.id' => ['nullable', 'string', 'max:100'],
            'messages.0.role' => ['required', 'in:user,assistant'],
        ];

        if ($this->input('messages.0.role') === 'assistant') {
            return [
                ...$base,
                'messages.0.parts' => ['required', 'array', 'min:1', 'max:'.self::MAX_ASSISTANT_PARTS],
                'messages.0.parts.*.type' => ['required', 'string', 'max:100'],
                'messages.0.parts.*.toolCallId' => ['required', 'string', 'max:100'],
                'messages.0.parts.*.state' => ['required', 'string', 'max:50'],
                'messages.0.parts.*.approval' => ['nullable', 'array'],
                'messages.0.parts.*.approval.id' => ['nullable', 'string', 'max:100'],
                'messages.0.parts.*.approval.approved' => ['nullable', 'boolean'],
            ];
        }

        return [
            ...$base,
            'messages.0.parts' => ['required', 'array', 'size:1'],
            'messages.0.parts.0.type' => ['required', 'in:text'],
            'messages.0.parts.0.text' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('messages.0.role') !== 'assistant') {
                    return;
                }

                if (Vercel::approvalResponsesFrom($this->input('messages.0')) === []) {
                    $validator->errors()->add('messages.0', 'El mensaje del asistente debe traer una respuesta de confirmación.');
                }
            },
        ];
    }
}
