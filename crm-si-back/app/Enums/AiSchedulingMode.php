<?php

namespace App\Enums;

enum AiSchedulingMode: string
{
    // La IA agenda y el turno queda confirmado.
    case AUTO = 'auto';
    // Todo turno queda pendiente hasta que un vendedor lo acepta.
    case APPROVAL = 'approval';
    // La IA decide en cada caso según el criterio escrito por el admin.
    case CRITERIA = 'criteria';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
