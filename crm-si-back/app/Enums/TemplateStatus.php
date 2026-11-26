<?php

namespace App\Enums;

enum TemplateStatus: string
{
    case Approved = 'APPROVED';
    case Pending = 'PENDING';
    case Rejected = 'REJECTED';
    case Disabled = 'DISABLED';
    case InAppeal = 'IN_APPEAL';
    case PendingDeletion = 'PENDING_DELETION';
    case Deleted = 'DELETED';
    case Paused = 'PAUSED';
    case LimitExceeded = 'LIMIT_EXCEEDED';
    case Unknown = 'UNKNOWN';

    /**
     * Obtener todos los valores posibles
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Obtener la descripción del estado
     */
    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprobado',
            self::Pending => 'Pendiente',
            self::Rejected => 'Rechazado',
            self::Disabled => 'Deshabilitado',
            self::InAppeal => 'En apelación',
            self::PendingDeletion => 'Eliminación pendiente',
            self::Deleted => 'Eliminado',
            self::Paused => 'Pausado',
            self::LimitExceeded => 'Límite excedido',
            self::Unknown => 'Estado desconocido',
        };
    }

    /**
     * Verificar si el template está aprobado
     */
    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    /** Meta sólo deja editar plantillas aprobadas, rechazadas o pausadas. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::Paused], true);
    }
}
