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
     * Devuelve `Y-m-d` si el valor es una fecha parseable, o null si no lo es
     * (el valor original queda intacto y la validación de tipo lo rechaza
     * después con el mensaje de campo del usuario). Pública para el import de
     * CSV (ContactImportService::castRawValue), que castea celda por celda
     * antes de tener armado el array completo de custom_data.
     */
    public static function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
