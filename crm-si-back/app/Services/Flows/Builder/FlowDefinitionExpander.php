<?php

namespace App\Services\Flows\Builder;

/**
 * Convierte los grupos repetibles del constructor en pantallas comunes, una
 * por fila, con la pregunta "¿Agregar otro?" que elige el camino. El
 * compilador, los caminos, el recorrido de la sesión y las etiquetas trabajan
 * sobre la definición expandida; el grupo sólo existe en la definición que
 * edita el administrador. Es determinística: la estructura del grupo (sus
 * subcampos) viaja copiada en la definición, no se lee del CRM.
 *
 * Ids: la primera pantalla del grupo conserva el id del grupo (los destinos
 * que lo apuntan no cambian) y las siguientes son `<id>-<n>`. Claves de las
 * respuestas: `<key>_<fila>_<posición del subcampo>`, `<key>_<fila>_more`
 * ("¿Agregar otro?") y `<key>_has` (pregunta previa con mínimo de 0 filas).
 */
final class FlowDefinitionExpander
{
    public const REPEATER = 'repeater';

    public const YES = 'si';

    public const NO = 'no';

    /** Más opciones que esto no entran en una opción única de Meta: van en lista. */
    private const RADIO_MAX = 20;

    public static function isRepeater(mixed $screen): bool
    {
        return is_array($screen) && ($screen['type'] ?? null) === self::REPEATER;
    }

    /** Definición con cada grupo reemplazado por sus pantallas. */
    public static function expand(array $definition): array
    {
        if (! self::hasGroups($definition)) {
            return $definition;
        }

        $screens = [];
        $raw = array_values($definition['screens']);

        foreach ($raw as $index => $screen) {
            if (! self::isRepeater($screen)) {
                $screens[] = $screen;

                continue;
            }

            // "La siguiente" del grupo es la pantalla que le sigue en la lista
            // original: dentro de la lista expandida apuntaría a su propia fila 2.
            $next = (string) ($screen['next'] ?? FlowDefinitionRoutes::FOLLOWING);
            if ($next === FlowDefinitionRoutes::FOLLOWING) {
                $following = $raw[$index + 1]['id'] ?? null;
                $screen['next'] = is_string($following) ? FlowDefinitionRoutes::SCREEN_PREFIX.$following : FlowDefinitionRoutes::END;
            }

            array_push($screens, ...self::groupScreens($screen));
        }

        return ['screens' => $screens] + $definition;
    }

    /** Pantallas que genera el formulario una vez expandido. */
    public static function screenCount(array $definition): int
    {
        $count = 0;

        foreach ($definition['screens'] ?? [] as $screen) {
            $count += self::isRepeater($screen) ? self::groupScreenCount($screen) : 1;
        }

        return $count;
    }

    public static function groupScreenCount(array $group): int
    {
        return max(1, (int) ($group['max_rows'] ?? 1)) + (self::asksFirst($group) ? 1 : 0);
    }

    /**
     * Grupos del formulario, con lo necesario para armar las filas de una
     * respuesta sin volver a leer la definición.
     *
     * @return list<array{key: string, field_key: string, title: string, row_title: string, max_rows: int, fields: array<int, array{key: string, label: string, type: string}>}>
     */
    public static function groups(array $definition): array
    {
        $groups = [];

        foreach ($definition['screens'] ?? [] as $screen) {
            if (! self::isRepeater($screen)) {
                continue;
            }

            $fields = [];
            foreach (array_values($screen['fields'] ?? []) as $i => $field) {
                $fields[$i + 1] = [
                    'key' => (string) ($field['key'] ?? ''),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => (string) ($field['type'] ?? 'text'),
                ];
            }

            $groups[] = [
                'key' => (string) $screen['key'],
                'field_key' => (string) ($screen['field_key'] ?? ''),
                'title' => (string) ($screen['title'] ?? ''),
                'row_title' => (string) ($screen['row_title'] ?? ''),
                'max_rows' => max(1, (int) ($screen['max_rows'] ?? 1)),
                'fields' => $fields,
            ];
        }

        return $groups;
    }

    public static function rowKey(string $key, int $row, int $field): string
    {
        return "{$key}_{$row}_{$field}";
    }

    public static function moreKey(string $key, int $row): string
    {
        return "{$key}_{$row}_more";
    }

    public static function hasKey(string $key): string
    {
        return "{$key}_has";
    }

    public static function rowTitle(array $group, int $row): string
    {
        return str_replace('{n}', (string) $row, (string) ($group['row_title'] ?? ''));
    }

    /** Si la clave es una respuesta generada por el grupo `$key`. */
    public static function ownsKey(string $groupKey, string $key): bool
    {
        return $key === self::hasKey($groupKey)
            || preg_match('/^'.preg_quote($groupKey, '/').'_\d+_(\d+|more)$/', $key) === 1;
    }

    private static function hasGroups(array $definition): bool
    {
        foreach ($definition['screens'] ?? [] as $screen) {
            if (self::isRepeater($screen)) {
                return true;
            }
        }

        return false;
    }

    private static function asksFirst(array $group): bool
    {
        return ($group['ask_first'] ?? false) === true && (int) ($group['min_rows'] ?? 0) === 0;
    }

    /** @return list<array<string, mixed>> */
    private static function groupScreens(array $group): array
    {
        $id = (string) $group['id'];
        $key = (string) $group['key'];
        $next = (string) ($group['next'] ?? FlowDefinitionRoutes::FOLLOWING);
        $maxRows = max(1, (int) ($group['max_rows'] ?? 1));
        $minRows = (int) ($group['min_rows'] ?? 0);
        $asksFirst = self::asksFirst($group);
        $footer = (string) ($group['footer_label'] ?? '');

        $rowId = fn (int $row) => $row === 1 && ! $asksFirst ? $id : "{$id}-{$row}";
        $screens = [];

        if ($asksFirst) {
            $screens[] = [
                'id' => $id,
                'title' => (string) ($group['title'] ?? ''),
                'footer_label' => $footer,
                'repeater' => $key,
                'blocks' => [self::yesNo(self::hasKey($key), (string) ($group['ask_first_label'] ?? ''), FlowDefinitionRoutes::SCREEN_PREFIX.$rowId(1), $next)],
                'next' => $next,
            ];
        }

        for ($row = 1; $row <= $maxRows; $row++) {
            $title = self::rowTitle($group, $row);
            $blocks = [];

            foreach (array_values($group['fields'] ?? []) as $i => $field) {
                $blocks[] = self::fieldBlock($field, self::rowKey($key, $row, $i + 1), $title);
            }

            $last = $row === $maxRows;
            $following = $last ? $next : FlowDefinitionRoutes::SCREEN_PREFIX.$rowId($row + 1);

            if (! $last && $row >= max($minRows, 1)) {
                $blocks[] = self::yesNo(self::moreKey($key, $row), (string) ($group['more_label'] ?? ''), $following, $next);
                $following = $next;
            }

            $screens[] = [
                'id' => $rowId($row),
                'title' => $title,
                'footer_label' => $footer,
                'repeater' => $key,
                'blocks' => $blocks,
                'next' => $following,
            ];
        }

        return $screens;
    }

    /** Pregunta Sí/No obligatoria que elige el camino. */
    private static function yesNo(string $key, string $label, string $yes, string $no): array
    {
        return [
            'type' => 'single_choice',
            'key' => $key,
            'label' => $label,
            'required' => true,
            'options' => [['id' => self::YES, 'title' => 'Sí'], ['id' => self::NO, 'title' => 'No']],
            'branches' => [self::YES => $yes, self::NO => $no],
        ];
    }

    /** Bloque de pregunta para un subcampo del repeater, según su tipo. */
    private static function fieldBlock(array $field, string $key, string $rowTitle): array
    {
        $label = (string) ($field['label'] ?? '');
        $base = [
            'key' => $key,
            'label' => $label,
            'display_label' => "{$label} · {$rowTitle}",
            'required' => ($field['required'] ?? false) === true,
        ];

        return match ($field['type'] ?? 'text') {
            'number' => $base + ['type' => 'short_text', 'input_type' => 'number'],
            'currency' => $base + ['type' => 'short_text', 'input_type' => 'number']
                + (filled($field['currency'] ?? null) ? ['helper_text' => 'En '.$field['currency']] : []),
            'email' => $base + ['type' => 'short_text', 'input_type' => 'email'],
            'phone' => $base + ['type' => 'short_text', 'input_type' => 'phone'],
            'date' => $base + ['type' => 'date'],
            'boolean' => $base + ['type' => 'opt_in'],
            'select' => $base + [
                'type' => count($field['choices'] ?? []) > self::RADIO_MAX ? 'dropdown' : 'single_choice',
                'options' => array_map(fn ($choice) => ['id' => (string) $choice, 'title' => (string) $choice], array_values($field['choices'] ?? [])),
            ],
            default => $base + ['type' => 'short_text', 'input_type' => 'text'],
        };
    }
}
