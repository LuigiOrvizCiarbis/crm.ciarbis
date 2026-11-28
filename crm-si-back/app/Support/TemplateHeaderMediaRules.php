<?php

namespace App\Support;

/**
 * Reglas para la URL del archivo de encabezado (image/video/document) que
 * viaja en los componentes de envío de una plantilla.
 *
 * Meta rechaza con "(#100) Param template.components.parameters.image.link is
 * not a valid URI" una URL sin esquema (`www.…`), con espacios u otros
 * caracteres sin codificar. Validarla al recibirla evita que una difusión
 * falle en cada uno de sus destinatarios.
 */
final class TemplateHeaderMediaRules
{
    private const KINDS = ['image', 'video', 'document'];

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        $rules = [];
        foreach (self::KINDS as $kind) {
            $rules["components.*.parameters.*.{$kind}.link"] = ['sometimes', 'string', 'max:2000', 'url:http,https'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        $messages = [];
        foreach (self::KINDS as $kind) {
            $messages["components.*.parameters.*.{$kind}.link.url"] = 'La URL del archivo de encabezado no es válida: pegá la dirección completa (https://…), sin espacios.';
        }

        return $messages;
    }
}
