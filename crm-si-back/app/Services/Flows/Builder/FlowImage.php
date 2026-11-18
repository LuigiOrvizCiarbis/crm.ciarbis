<?php

namespace App\Services\Flows\Builder;

/**
 * Imagen embebida en el Flow JSON: Meta no acepta URLs, sólo base64 de un
 * JPEG o PNG (sin el prefijo `data:`).
 */
final readonly class FlowImage
{
    private function __construct(
        public int $bytes,
        public int $width,
        public int $height,
    ) {}

    /** Null si no es base64 válido de un JPEG o PNG. */
    public static function decode(mixed $src): ?self
    {
        if (! is_string($src) || $src === '') {
            return null;
        }

        $binary = base64_decode($src, true);
        $info = $binary === false ? false : @getimagesizefromstring($binary);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        return new self(strlen($binary), $info[0], $info[1]);
    }

    public function aspectRatio(): float
    {
        return round($this->width / $this->height, 4);
    }
}
