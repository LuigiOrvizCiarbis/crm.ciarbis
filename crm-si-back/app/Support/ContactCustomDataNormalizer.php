<?php

namespace App\Support;

use App\Enums\ContactFieldType;
use App\Models\ContactField;

/**
 * Normaliza los valores de campos `Date` a `Y-m-d` antes de guardarlos.
 *
 * Los filtros de rango (Contact::scopeWhereCustomFieldRange) comparan la fecha
 * guardada como texto: `custom_data ->> key BETWEEN ? AND ?`. Eso solo ordena
 * bien si todos los contactos guardan el mismo formato. Sin este paso, un
 * valor cargado como "03/09/2026" o con hora incluida ordena mal contra un
 * "2026-09-03" y el contacto desaparece del filtro sin ningún error visible.
 *
 * Se aplica ANTES de validar (ValidContactCustomData no puede mutar el valor
 * que después se guarda), en los tres puntos que escriben custom_data:
 * ContactController::store/update, WebhookContactUpsertService y
 * DocumentExtractionController.
 */
final class ContactCustomDataNormalizer
{
    /**
     * @param  array<string, mixed>  $customData
     * @return array<string, mixed>
     */
    public static function normalize(array $customData, int $tenantId): array
    {
        if ($customData === []) {
            return $customData;
        }

        $dateFields = ContactField::forTenant($tenantId)
            ->filter(fn (ContactField $field) => $field->type === ContactFieldType::Date)
            ->keyBy('key');

        foreach ($customData as $key => $value) {
            if (! $dateFields->has($key)) {
                continue;
            }

            $normalized = self::normalizeDate($value);
            if ($normalized !== null) {
                $customData[$key] = $normalized;
            }
        }

        return $customData;
    }

    /**
     * Formatos día-primero, probados antes del parseo genérico de PHP.
     *
     * `DateTimeImmutable` lee "31/05/2021" como mes 31 (convención de EE.UU.)
     * y tira excepción, y lo peor: "11/07/2020" lo lee como 7 de noviembre sin
     * error alguno. Como los CSV que importan los clientes vienen en d/m/Y,
     * estos formatos van primero y el constructor genérico queda de fallback
     * para ISO, "Y-m-d H:i:s" y demás.
     *
     * Cada formato va con el largo de año que le corresponde: `Y` también
     * acepta años de dos dígitos, así que sin esa restricción "31/05/21"
     * matchearía `!d/m/Y` y saldría como el año 21.
     *
     * @var list<array{0: non-empty-string, 1: int}>
     */
    private const DAY_FIRST_FORMATS = [
        ['!d/m/Y', 4],
        ['!d-m-Y', 4],
        ['!d.m.Y', 4],
        ['!d/m/y', 2],
        ['!d-m-y', 2],
        ['!d.m.y', 2],
    ];

    /**
     * Devuelve `Y-m-d` si el valor es una fecha parseable, o null si no lo es
     * (el valor original queda intacto y la validación de tipo lo rechaza
     * después con el mensaje de campo del usuario). Pública para el import de
     * CSV (ContactImportService::castRawValue), que castea celda por celda
     * antes de tener armado el array completo de custom_data.
     */
    public static function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || ($trimmed = trim($value)) === '') {
            return null;
        }

        foreach (self::DAY_FIRST_FORMATS as [$format, $yearDigits]) {
            if (! self::hasYearOfLength($trimmed, $yearDigits)) {
                continue;
            }

            $parsed = \DateTimeImmutable::createFromFormat($format, $trimmed);

            // getLastErrors() descarta desbordes como "31/02/2021", que
            // createFromFormat aceptaría corriéndolo al 3 de marzo.
            if ($parsed !== false && self::parsedCleanly()) {
                return $parsed->format('Y-m-d');
            }
        }

        try {
            return (new \DateTimeImmutable($trimmed))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * `d/m/y` y `d/m/Y` matchean la misma entrada, así que el largo del último
     * grupo de dígitos decide cuál de los dos corresponde.
     */
    private static function hasYearOfLength(string $value, int $digits): bool
    {
        return preg_match('/(\d+)\s*$/', $value, $matches) === 1
            && strlen($matches[1]) === $digits;
    }

    private static function parsedCleanly(): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();

        return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
    }
}
