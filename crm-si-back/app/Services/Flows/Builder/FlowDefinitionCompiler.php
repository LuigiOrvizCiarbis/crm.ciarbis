<?php

namespace App\Services\Flows\Builder;

/**
 * Compila la definición del constructor visual a Flow JSON de Meta.
 * Asume una definición ya validada por FlowDefinitionValidator.
 *
 * Todas las pantallas pasan por el endpoint del CRM (`data_exchange` con
 * `crm_action: submit_screen`): ahí se validan las respuestas con las reglas
 * del CRM, se guarda el progreso y se decide la pantalla siguiente. Los
 * textos se restauran con `init-value` desde los datos de la pantalla, que
 * arma FlowEndpointHandler con lo ya respondido o la precarga del contacto.
 *
 * Las claves `crm_action`, `next_screen`, `date` y `slot`, y los datos de las
 * pantallas de turno (`available_dates`, `available_slots`, `selected_date`)
 * son el contrato con FlowEndpointHandler.
 */
class FlowDefinitionCompiler
{
    public const FLOW_JSON_VERSION = '7.3';

    public const DATA_API_VERSION = '4.0';

    public const SUBMIT_ACTION = 'submit_screen';

    public const DATE_SCREEN = 'TURNO_FECHA';

    public const SLOT_SCREEN = 'TURNO_HORARIO';

    /** Preguntas cuya respuesta se restaura al volver a abrir el formulario. */
    public const RESTORABLE_TYPES = ['short_text', 'long_text'];

    private const COMPONENTS = [
        'heading' => 'TextHeading',
        'subheading' => 'TextSubheading',
        'body' => 'TextBody',
        'caption' => 'TextCaption',
        'short_text' => 'TextInput',
        'long_text' => 'TextArea',
        'dropdown' => 'Dropdown',
        'single_choice' => 'RadioButtonsGroup',
        'multiple_choice' => 'CheckboxGroup',
        'date' => 'DatePicker',
        'opt_in' => 'OptIn',
    ];

    private const OPTION_LIST_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string'], 'title' => ['type' => 'string']],
        ],
    ];

    public function compile(array $definition): CompiledFlow
    {
        $screens = $definition['screens'];
        $appointment = $definition['appointment'] ?? null;
        $mappings = [];

        foreach ($screens as $screen) {
            foreach ($screen['blocks'] as $block) {
                if ($this->isQuestion($block) && ! empty($block['maps_to'])) {
                    $mappings[] = ['response_key' => $block['key'], 'target' => $block['maps_to']];
                }
            }
        }

        $compiled = [];
        $last = count($screens) - 1;

        foreach ($screens as $i => $screen) {
            $next = $i < $last ? self::screenId($i + 1) : ($appointment !== null ? self::DATE_SCREEN : null);
            $compiled[] = $this->compileScreen($screen, $i, $next);
        }

        if ($appointment !== null) {
            $compiled[] = $this->dateScreen($appointment);
            $compiled[] = $this->slotScreen($appointment);
        }

        return new CompiledFlow([
            'version' => self::FLOW_JSON_VERSION,
            'data_api_version' => self::DATA_API_VERSION,
            'routing_model' => $this->routingModel(count($screens), $appointment !== null),
            'screens' => $compiled,
        ], $mappings);
    }

    public static function screenId(int $index): string
    {
        // Meta sólo admite letras y guiones bajos en el id de pantalla.
        return 'PANTALLA_'.chr(ord('A') + $index);
    }

    /** Índice de una pantalla de preguntas a partir de su id, o null. */
    public static function screenIndex(string $screenId): ?int
    {
        return preg_match('/^PANTALLA_([A-Z])$/', $screenId, $match) === 1 ? ord($match[1]) - ord('A') : null;
    }

    /** @param  ?string  $next  pantalla siguiente; null si esta pantalla cierra el formulario */
    private function compileScreen(array $screen, int $index, ?string $next): array
    {
        $children = [];
        $answers = [];
        $data = [];

        foreach ($screen['blocks'] as $block) {
            $children[] = $this->compileBlock($block);

            if (! $this->isQuestion($block)) {
                continue;
            }

            $answers[$block['key']] = '${form.'.$block['key'].'}';

            if (in_array($block['type'], self::RESTORABLE_TYPES, true)) {
                $data[$block['key']] = ['type' => 'string', '__example__' => ''];
            }
        }

        $payload = ['crm_action' => self::SUBMIT_ACTION] + ($next !== null ? ['next_screen' => $next] : []) + $answers;
        $children[] = $this->footer($screen['footer_label'], ['name' => 'data_exchange', 'payload' => $payload]);

        $compiled = ['id' => self::screenId($index), 'title' => $screen['title']];

        if ($next === null) {
            $compiled['terminal'] = true;
            $compiled['success'] = true;
        }

        if ($data !== []) {
            $compiled['data'] = $data;
        }

        $compiled['layout'] = ['type' => 'SingleColumnLayout', 'children' => $children];

        return $compiled;
    }

    private function compileBlock(array $block): array
    {
        $type = $block['type'];
        $component = ['type' => self::COMPONENTS[$type]];

        if (! $this->isQuestion($block)) {
            return $component + ['text' => $block['text']];
        }

        $component += [
            'name' => $block['key'],
            'label' => $block['label'],
            'required' => (bool) ($block['required'] ?? false),
        ];

        if ($type === 'short_text') {
            $component['input-type'] = $block['input_type'] ?? 'text';
        }

        if (in_array($type, ['short_text', 'long_text', 'date'], true) && ! empty($block['helper_text'])) {
            $component['helper-text'] = $block['helper_text'];
        }

        if (in_array($type, ['dropdown', 'single_choice', 'multiple_choice'], true)) {
            $component['data-source'] = array_map(
                fn (array $option) => ['id' => (string) $option['id'], 'title' => (string) $option['title']],
                $block['options'],
            );
        }

        if (in_array($type, self::RESTORABLE_TYPES, true)) {
            $component['init-value'] = '${data.'.$block['key'].'}';
        }

        return $component;
    }

    private function dateScreen(array $appointment): array
    {
        return [
            'id' => self::DATE_SCREEN,
            'title' => $appointment['date_screen_title'],
            'data' => [
                'available_dates' => self::OPTION_LIST_SCHEMA + ['__example__' => [['id' => '2026-01-05', 'title' => 'lun 5 de ene']]],
            ],
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [
                    $this->dynamicDropdown('date', $appointment['date_label'], '${data.available_dates}'),
                    $this->footer('Continuar', [
                        'name' => 'data_exchange',
                        'payload' => [
                            'crm_action' => 'appointment_slots',
                            'next_screen' => self::SLOT_SCREEN,
                            'date' => '${form.date}',
                        ],
                    ]),
                ],
            ],
        ];
    }

    /** El email y el motivo del turno los toma el endpoint de las respuestas guardadas. */
    private function slotScreen(array $appointment): array
    {
        return [
            'id' => self::SLOT_SCREEN,
            'title' => $appointment['slot_screen_title'],
            'terminal' => true,
            'data' => [
                'available_slots' => self::OPTION_LIST_SCHEMA + ['__example__' => [['id' => '2026-01-05T10:00:00-03:00', 'title' => '10:00']]],
                'selected_date' => ['type' => 'string', '__example__' => '2026-01-05'],
            ],
            'layout' => [
                'type' => 'SingleColumnLayout',
                'children' => [
                    $this->dynamicDropdown('slot', $appointment['slot_label'], '${data.available_slots}'),
                    $this->footer($appointment['confirm_label'], [
                        'name' => 'data_exchange',
                        'payload' => ['crm_action' => 'book_appointment', 'slot' => '${form.slot}'],
                    ]),
                ],
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function routingModel(int $screenCount, bool $withAppointment): array
    {
        $routes = [];

        for ($i = 0; $i < $screenCount - 1; $i++) {
            $routes[self::screenId($i)] = [self::screenId($i + 1)];
        }

        $routes[self::screenId($screenCount - 1)] = $withAppointment ? [self::DATE_SCREEN] : [];

        if ($withAppointment) {
            $routes[self::DATE_SCREEN] = [self::SLOT_SCREEN];
            $routes[self::SLOT_SCREEN] = [];
        }

        return $routes;
    }

    private function isQuestion(array $block): bool
    {
        return isset(FlowDefinitionValidator::QUESTION_TYPES[$block['type']]);
    }

    private function dynamicDropdown(string $name, string $label, string $source): array
    {
        return ['type' => 'Dropdown', 'name' => $name, 'label' => $label, 'required' => true, 'data-source' => $source];
    }

    private function footer(string $label, array $action): array
    {
        return ['type' => 'Footer', 'label' => $label, 'on-click-action' => $action];
    }
}
