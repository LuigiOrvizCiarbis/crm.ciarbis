<?php

namespace App\Services\Flows;

use App\Services\Flows\Builder\FlowDefinitionExpander;

/**
 * Arma las filas de un grupo repetible a partir de las respuestas sueltas del
 * formulario (`<grupo>_<fila>_<posición>`). Las respuestas ya vienen filtradas
 * por el recorrido, así que una fila existe si alguna de sus claves llegó.
 */
final class FlowRepeaterAnswers
{
    /**
     * @param  array<int, string>  $fields  posición del subcampo (desde 1) → clave del subcampo
     * @param  array<string, string>  $types  clave del subcampo → tipo, para convertir los valores
     * @return list<array<string, mixed>>|null null si el grupo no se respondió
     */
    public static function rows(string $key, array $fields, int $maxRows, array $answers, array $types = []): ?array
    {
        if (($answers[FlowDefinitionExpander::hasKey($key)] ?? null) === FlowDefinitionExpander::NO) {
            return [];
        }

        $rows = [];

        for ($row = 1; $row <= $maxRows; $row++) {
            $keys = array_map(fn (int $position) => FlowDefinitionExpander::rowKey($key, $row, $position), array_keys($fields));

            if (array_intersect_key($answers, array_flip($keys)) === []) {
                break;
            }

            $values = [];
            foreach ($fields as $position => $subKey) {
                $value = self::convert($answers[FlowDefinitionExpander::rowKey($key, $row, $position)] ?? null, $types[$subKey] ?? 'text');

                if ($value !== null) {
                    $values[$subKey] = $value;
                }
            }

            if ($values !== []) {
                $rows[] = $values;
            }
        }

        return $rows === [] && $row === 1 ? null : $rows;
    }

    /** Filas que trae esta pantalla: número de fila → claves de sus respuestas. */
    public static function rowsIn(string $key, array $fields, int $maxRows, array $answers): array
    {
        $present = [];

        for ($row = 1; $row <= $maxRows; $row++) {
            foreach (array_keys($fields) as $position) {
                if (array_key_exists(FlowDefinitionExpander::rowKey($key, $row, $position), $answers)) {
                    $present[] = $row;
                    break;
                }
            }
        }

        return $present;
    }

    /** Valor como lo guarda el repeater: números como número, Sí/No como booleano; vacío es null. */
    public static function convert(mixed $value, string $type): mixed
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ($type) {
            'number', 'currency' => is_string($value) && is_numeric($value) ? $value + 0 : $value,
            'boolean' => is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $value),
            default => is_int($value) || is_float($value) ? (string) $value : $value,
        };
    }
}
