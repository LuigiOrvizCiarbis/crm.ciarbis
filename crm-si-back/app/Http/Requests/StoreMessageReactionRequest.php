<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validación laxa a propósito: intentar exigir "es exactamente un
     * emoji" con regex es una trampa (los compuestos con ZWJ, banderas y
     * selectores de variación rompen cualquier regex ingenua, y el set
     * crece con cada versión de Unicode). El límite de longitud alcanza;
     * Meta rechaza lo inválido con un error tipado que ya mapeamos.
     * `emoji: ""` o ausente es el contrato de Meta para quitar la reacción.
     */
    public function rules(): array
    {
        return [
            'emoji' => ['present', 'nullable', 'string', 'max:32'],
        ];
    }
}
