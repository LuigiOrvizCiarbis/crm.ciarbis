<?php

namespace App\Support;

use App\Enums\ContactFieldType;
use Illuminate\Support\Str;

/**
 * Shared contract for the nested schema stored in a repeater field's options.
 */
final class RepeaterFieldSchema
{
    /** @var list<string> */
    public const TYPES = ['text', 'number', 'currency', 'date', 'boolean', 'select', 'email', 'url', 'phone'];

    public const DEFAULT_MIN_ITEMS = 0;

    public const DEFAULT_MAX_ITEMS = 10;

    public const MAX_ITEMS = 100;

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public static function errors(array $options): array
    {
        $errors = [];
        $fields = $options['fields'] ?? null;
        if (! is_array($fields) || count($fields) === 0) {
            $errors[] = 'Debe definir al menos un subcampo.';

            return $errors;
        }

        $min = $options['min_items'] ?? self::DEFAULT_MIN_ITEMS;
        $max = $options['max_items'] ?? self::DEFAULT_MAX_ITEMS;
        if (! is_int($min) || $min < 0 || $min > self::MAX_ITEMS) {
            $errors[] = 'El mínimo de filas debe estar entre 0 y 100.';
        }
        if (! is_int($max) || $max < 0 || $max > self::MAX_ITEMS) {
            $errors[] = 'El máximo de filas debe estar entre 0 y 100.';
        }
        if (is_int($min) && is_int($max) && $min > $max) {
            $errors[] = 'El mínimo de filas no puede superar al máximo.';
        }

        $keys = [];
        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                $errors[] = "El subcampo {$index} no es válido.";

                continue;
            }
            $key = $field['key'] ?? null;
            if ($key !== null && (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key))) {
                $errors[] = "La clave del subcampo {$index} no es válida.";
            }
            if (is_string($key)) {
                if (isset($keys[$key])) {
                    $errors[] = "La clave del subcampo {$index} está repetida.";
                }
                $keys[$key] = true;
            }

            if (! is_string($field['label'] ?? null) || trim($field['label']) === '' || mb_strlen($field['label']) > 120) {
                $errors[] = "El nombre del subcampo {$index} no es válido.";
            }
            if (! in_array($field['type'] ?? null, self::TYPES, true)) {
                $errors[] = "El tipo del subcampo {$index} no es válido.";
            }
            if (($field['type'] ?? null) === 'currency') {
                $currency = $field['options']['currency'] ?? null;
                if ($currency !== null && ! in_array($currency, ContactFieldType::currencies(), true)) {
                    $errors[] = "La divisa del subcampo {$index} no es válida.";
                }
            }
            if (($field['type'] ?? null) === 'select') {
                $choices = $field['options']['choices'] ?? null;
                if (! is_array($choices) || count($choices) === 0 || collect($choices)->contains(fn ($choice) => ! is_string($choice) || trim($choice) === '')) {
                    $errors[] = "El subcampo de selección {$index} necesita opciones.";
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public static function valueErrors(mixed $value, array $options): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value)) {
            return ['debe ser una lista de filas.'];
        }

        $min = (int) ($options['min_items'] ?? self::DEFAULT_MIN_ITEMS);
        $max = (int) ($options['max_items'] ?? self::DEFAULT_MAX_ITEMS);
        $count = count($value);
        $errors = [];
        if ($count < $min) {
            $errors[] = "debe contener al menos {$min} fila(s).";
        }
        if ($count > $max) {
            $errors[] = "no puede contener más de {$max} fila(s).";
        }

        $fields = [];
        foreach ((array) ($options['fields'] ?? []) as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $fields[$field['key']] = $field;
            }
        }
        foreach ($value as $rowIndex => $row) {
            if (! is_array($row)) {
                $errors[] = "la fila {$rowIndex} debe ser un objeto.";

                continue;
            }
            $hasValue = false;
            foreach ($row as $key => $raw) {
                if (! isset($fields[$key])) {
                    $errors[] = "la fila {$rowIndex} contiene el subcampo desconocido {$key}.";

                    continue;
                }
                if ($raw !== null && $raw !== '' && $raw !== []) {
                    $hasValue = true;
                }
                $fieldErrors = self::scalarErrors($raw, $fields[$key]);
                foreach ($fieldErrors as $error) {
                    $errors[] = "la fila {$rowIndex}, {$fields[$key]['label']}: {$error}";
                }
            }
            if (! $hasValue) {
                $errors[] = "la fila {$rowIndex} no puede estar vacía.";
            }
            foreach ($fields as $key => $field) {
                if (($field['is_active'] ?? true) && ($field['is_required'] ?? false)) {
                    $raw = $row[$key] ?? null;
                    if ($raw === null || $raw === '' || $raw === []) {
                        $errors[] = "la fila {$rowIndex}, {$field['label']}: es requerido.";
                    }
                }
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $field @return list<string> */
    private static function scalarErrors(mixed $value, array $field): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $type = $field['type'] ?? 'text';
        $choices = $field['options']['choices'] ?? [];

        return match ($type) {
            'text' => is_string($value) && mb_strlen($value) <= 1000 ? [] : ['debe ser texto.'],
            'number', 'currency' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? [] : ['debe ser numérico.'],
            'date' => is_string($value) && self::validDate($value) ? [] : ['debe ser una fecha válida.'],
            'boolean' => is_bool($value) ? [] : ['debe ser booleano.'],
            'select' => is_string($value) && in_array($value, $choices, true) ? [] : ['no es una opción válida.'],
            'email' => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? [] : ['debe ser un email válido.'],
            'url' => is_string($value) && filter_var($value, FILTER_VALIDATE_URL) ? [] : ['debe ser una URL válida.'],
            'phone' => is_string($value) && mb_strlen($value) <= 50 ? [] : ['debe ser texto.'],
            default => ['tipo no soportado.'],
        };
    }

    private static function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * Normalizes client input and preserves omitted existing subfields as archived.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public static function normalize(array $options, ?array $existing = null): array
    {
        $incoming = is_array($options['fields'] ?? null) ? $options['fields'] : [];
        $existingFields = is_array($existing['fields'] ?? null) ? $existing['fields'] : [];
        $existingByKey = [];
        foreach ($existingFields as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $existingByKey[$field['key']] = $field;
            }
        }

        // Reserve every historical key before generating keys for incoming
        // keyless definitions. Otherwise an omitted field could be silently
        // replaced by a new definition with the same generated key.
        $used = array_fill_keys(array_keys($existingByKey), true);
        $seenIncoming = [];
        $normalized = [];
        foreach ($incoming as $field) {
            if (! is_array($field)) {
                continue;
            }
            $providedKey = is_string($field['key'] ?? null) && preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field['key'])
                ? $field['key']
                : null;
            $key = $providedKey !== null && isset($existingByKey[$providedKey]) && ! isset($seenIncoming[$providedKey])
                ? $providedKey
                : self::uniqueKey((string) ($providedKey ?? $field['label'] ?? 'subcampo'), $used);
            $used[$key] = true;
            $seenIncoming[$key] = true;
            $type = (string) ($field['type'] ?? 'text');
            $item = [
                'key' => $key,
                'label' => trim((string) ($field['label'] ?? $key)),
                'type' => $type,
                'options' => match ($type) {
                    'select' => ['choices' => array_values(array_filter(
                        is_array($field['options']['choices'] ?? null) ? $field['options']['choices'] : [],
                        'is_string',
                    ))],
                    'currency' => ['currency' => in_array($field['options']['currency'] ?? null, ContactFieldType::currencies(), true)
                        ? $field['options']['currency']
                        : ContactFieldType::DEFAULT_CURRENCY],
                    default => null,
                },
                'is_required' => (bool) ($field['is_required'] ?? false),
                'is_active' => (bool) ($field['is_active'] ?? true),
            ];
            $normalized[] = $item;
            unset($existingByKey[$key]);
        }

        // Existing definitions not sent by the client are archived, never dropped.
        foreach ($existingByKey as $field) {
            $field['is_active'] = false;
            $normalized[] = $field;
        }

        return [
            'fields' => $normalized,
            'min_items' => max(0, min(self::MAX_ITEMS, (int) ($options['min_items'] ?? self::DEFAULT_MIN_ITEMS))),
            'max_items' => max(0, min(self::MAX_ITEMS, (int) ($options['max_items'] ?? self::DEFAULT_MAX_ITEMS))),
        ];
    }

    /** @param array<string, bool> $used */
    private static function uniqueKey(string $label, array $used): string
    {
        $base = Str::slug($label, '_');
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower($base)) ?: 'subcampo';
        if (! preg_match('/^[a-z]/', $base)) {
            $base = 'campo_'.$base;
        }
        $base = substr($base, 0, 54);
        $key = $base;
        $suffix = 2;
        while (isset($used[$key])) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }
}
