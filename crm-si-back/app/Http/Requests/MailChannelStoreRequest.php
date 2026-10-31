<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MailChannelStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Datos de una casilla genérica IMAP/SMTP. El controlador prueba la conexión
     * con estas credenciales antes de persistir nada.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $encryptions = ['ssl', 'tls', 'none'];

        return [
            'email_address' => 'required|email:rfc|max:255',
            'password' => 'required|string|max:1024',
            'from_name' => 'sometimes|nullable|string|max:255',
            'imap_host' => 'required|string|max:255',
            'imap_port' => 'required|integer|min:1|max:65535',
            'imap_encryption' => ['required', 'string', Rule::in($encryptions)],
            'smtp_host' => 'required|string|max:255',
            'smtp_port' => 'required|integer|min:1|max:65535',
            'smtp_encryption' => ['required', 'string', Rule::in($encryptions)],
            'name' => 'sometimes|nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'email_address.required' => 'Ingresá la dirección de email de la casilla.',
            'email_address.email' => 'La dirección de email no es válida.',
            'password.required' => 'Ingresá la contraseña o app-password de la casilla.',
            'imap_host.required' => 'Ingresá el servidor IMAP de entrada.',
            'smtp_host.required' => 'Ingresá el servidor SMTP de salida.',
            'imap_encryption.in' => 'La encriptación IMAP debe ser ssl, tls o none.',
            'smtp_encryption.in' => 'La encriptación SMTP debe ser ssl, tls o none.',
        ];
    }
}
