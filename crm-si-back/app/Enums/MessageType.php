<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Sticker = 'sticker';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
    case Contacts = 'contacts';
    case Flow = 'flow';
    case FlowResponse = 'flow_response';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Texto',
            self::Image => 'Imagen',
            self::Sticker => 'Sticker',
            self::Document => 'Documento',
            self::Audio => 'Audio',
            self::Video => 'Video',
            self::Contacts => 'Contactos',
            self::Flow => 'Formulario',
            self::FlowResponse => 'Respuesta de formulario',
        };
    }
}
