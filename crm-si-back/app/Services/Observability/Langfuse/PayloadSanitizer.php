<?php

namespace App\Services\Observability\Langfuse;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Prepara lo que se exporta a Langfuse: omite binarios (imágenes en base64),
 * corta strings enormes y enmascara emails, teléfonos y números de tarjeta.
 * El resto del contenido viaja tal cual: sin él, el trace no sirve para
 * evaluar la calidad de las respuestas.
 */
final class PayloadSanitizer
{
    private const MAX_DEPTH = 20;

    private const DATA_URI_MIN_CHARS = 256;

    private const BASE64_MIN_CHARS = 1024;

    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    // Fechas (2026-11-29, 29-11-2026, 29.11.2026): se dejan pasar antes de
    // buscar teléfonos; si no, "2026-11-29 09:00" junta 10 dígitos con la hora.
    private const DATE = '(?<!\d)(?:\d{4}-(?:0?[1-9]|1[0-2])-(?:0?[1-9]|[12]\d|3[01])'
        .'|(?:0?[1-9]|[12]\d|3[01])[\-.](?:0?[1-9]|1[0-2])[\-.]\d{4})(?!\d)';

    // Corridas de 8 a 19 dígitos con separadores opcionales: candidatas a
    // teléfono o tarjeta. El callback decide con la cantidad real de dígitos.
    private const DIGIT_RUN = '\+?\d(?:[\s\-.()]*\d){7,18}';

    private const DATE_OR_DIGIT_RUN_PATTERN = '/(?<date>'.self::DATE.')|'.self::DIGIT_RUN.'/';

    public function __construct(private readonly int $maxStringChars) {}

    public function sanitize(mixed $value): mixed
    {
        return $this->walk($value, null, 0);
    }

    private function walk(mixed $value, int|string|null $key, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return '[profundidad máxima]';
        }

        if (is_string($value)) {
            return $this->string($value, $key);
        }

        if (is_object($value)) {
            $value = $this->objectToArray($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $childKey => $child) {
            $result[$childKey] = $this->walk($child, $childKey, $depth + 1);
        }

        return $result;
    }

    private function string(string $value, int|string|null $key): string
    {
        if ($this->isBinary($value, $key)) {
            return str_starts_with($value, 'data:') ? '[data-uri omitido]' : '[base64 omitido]';
        }

        // Cortar antes de enmascarar: un documento de 120k caracteres no
        // necesita pasar entero por las regex.
        $length = mb_strlen($value);
        if ($length > $this->maxStringChars) {
            $value = mb_substr($value, 0, $this->maxStringChars).'…[truncado '.($length - $this->maxStringChars).' caracteres]';
        }

        return $this->mask($value);
    }

    private function isBinary(string $value, int|string|null $key): bool
    {
        if (str_starts_with($value, 'data:') && strlen($value) > self::DATA_URI_MIN_CHARS) {
            return true;
        }

        return $key === 'data'
            && strlen($value) > self::BASE64_MIN_CHARS
            && preg_match('/^[A-Za-z0-9+\/=\s]+$/', $value) === 1;
    }

    private function mask(string $value): string
    {
        $value = preg_replace(self::EMAIL_PATTERN, '[EMAIL]', $value) ?? $value;

        return preg_replace_callback(self::DATE_OR_DIGIT_RUN_PATTERN, function (array $match): string {
            $run = $match[0];
            if (($match['date'] ?? '') !== '') {
                return $run;
            }

            $digits = preg_replace('/\D/', '', $run);
            $count = strlen($digits);

            // Un "+" adelante es formato internacional: teléfono (E.164 tiene
            // hasta 15 dígitos; +54 9 11 … ya son 13, como una tarjeta).
            if (str_starts_with($run, '+') && $count <= 15) {
                return '[TELEFONO]';
            }

            if ($count >= 13 && $this->passesLuhn($digits)) {
                return '[TARJETA]';
            }

            // Con separadores y pocos dígitos suele ser una fecha o un precio.
            $hasSeparators = preg_match('/[\s\-.()]/', $run) === 1;
            if ($count <= 15 && (! $hasSeparators || $count >= 10)) {
                return '[TELEFONO]';
            }

            return $run;
        }, $value) ?? $value;
    }

    private function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }

    /** @return array<array-key, mixed> */
    private function objectToArray(object $value): array
    {
        if ($value instanceof \JsonSerializable) {
            $serialized = $value->jsonSerialize();

            return is_array($serialized) ? $serialized : ['value' => $serialized];
        }

        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof \UnitEnum) {
            return ['value' => $value instanceof \BackedEnum ? $value->value : $value->name];
        }

        return get_object_vars($value);
    }
}
