<?php

namespace App\Enums;

enum WhatsAppFlowStatus: string
{
    case Draft = 'DRAFT';
    case Published = 'PUBLISHED';
    case Deprecated = 'DEPRECATED';
    case Blocked = 'BLOCKED';
    case Throttled = 'THROTTLED';

    public function isSendable(): bool
    {
        return in_array($this, [self::Draft, self::Published, self::Throttled], true);
    }

    /**
     * Meta permite editar un formulario publicado: vuelve a borrador y los
     * envíos siguen usando la última versión publicada hasta re-publicarlo.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Published], true);
    }
}
