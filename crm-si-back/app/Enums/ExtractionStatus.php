<?php

namespace App\Enums;

enum ExtractionStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Confirmed = 'confirmed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Un estado terminal ya no cambia por sí solo. Se usa para que un failed()
     * tardío no pise un completed que ya se escribió.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Confirmed], true);
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Processing], true);
    }
}
