<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MessengerChannelStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Dos flujos mutuamente excluyentes:
     *  - `code`: primer intercambio del Facebook Login.
     *  - `onboarding_token` + `page_id`: segunda vuelta cuando el usuario tiene
     *    varias páginas y eligió una (el code ya se consumió en la primera).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => 'required_without:onboarding_token|nullable|string',
            'onboarding_token' => 'required_without:code|nullable|string',
            'page_id' => 'required_with:onboarding_token|nullable|string',
            'redirect_uri' => 'sometimes|nullable|string',
            'name' => 'sometimes|nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required_without' => 'Falta el código de autorización de Meta.',
            'onboarding_token.required_without' => 'Falta el token de onboarding o el código de autorización.',
            'page_id.required_with' => 'Debés elegir una página de Facebook.',
        ];
    }
}
