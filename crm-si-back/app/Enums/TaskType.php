<?php

namespace App\Enums;

enum TaskType: string
{
    case MEETING = 'reunion';
    case CALL = 'llamado';
    case DEMO = 'demo';
    case PROPOSAL = 'propuesta';
    case VISIT = 'visita';
    case FOLLOW_UP = 'seguimiento';
    case SUPPORT = 'soporte';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::MEETING => 'Reunión',
            self::CALL => 'Llamado',
            self::DEMO => 'Demo',
            self::PROPOSAL => 'Propuesta',
            self::VISIT => 'Visita',
            self::FOLLOW_UP => 'Seguimiento',
            self::SUPPORT => 'Soporte',
        };
    }
}
