<?php

namespace App\Services\Flows\Builder;

/**
 * Compila la definición del constructor visual a Flow JSON de Meta.
 * Asume una definición ya validada por FlowDefinitionValidator.
 *
 * Siguiendo la guía de Meta, el formulario se abre sin consultar al CRM (los
 * datos de la primera pantalla viajan en el envío) y el endpoint se usa sólo
 * en las pantallas que lo necesitan: las que siguen a otra pantalla o al
 * turno (el CRM guarda el progreso y elige el camino), y las que guardan
 * respuestas en el contacto (el CRM las valida). Esas envían
 * `data_exchange` con `crm_action: submit_screen`; el resto cierra con
 * `complete`. Un formulario de una pantalla sin mapeos ni turno no usa el
 * endpoint, así que tampoco necesita cifrado.
 *
 * Los textos se restauran con `init-value` desde los datos de la pantalla
 * (lo ya respondido o la precarga del contacto). Las claves `crm_action`,
 * `date` y `slot`, y los datos de las pantallas de turno (`available_dates`,
 * `available_slots`, `selected_date`) son el contrato con FlowEndpointHandler.
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
        $routes = FlowDefinitionRoutes::fromDefinition($definition);
        $mappings = [];
        $compiled = [];
        $routing = [];
        $usesEndpoint = false;

        foreach ($screens as $i => $screen) {
            $destinations = $routes->destinations($i);
            $ends = in_array(null, $destinations, true);
            $viaEndpoint = $appointment !== null || ! $ends || count($destinations) > 1 || $this->hasMappedQuestion($screen);
            $usesEndpoint = $usesEndpoint || $viaEndpoint;

            $compiled[] = $this->compileScreen($screen, $i, $viaEndpoint, $ends && $appointment === null);
            $routing[self::screenId($i)] = $this->screenRoutes($destinations, $appointment !== null);

            foreach ($screen['blocks'] as $block) {
                if ($this->isQuestion($block) && ! empty($block['maps_to'])) {
                    $mappings[] = ['response_key' => $block['key'], 'target' => $block['maps_to']];
                }
            }
        }

        $flowJson = ['version' => self::FLOW_JSON_VERSION];

        if ($appointment !== null) {
            $compiled[] = $this->dateScreen($appointment);
            $compiled[] = $this->slotScreen($appointment);
            $routing[self::DATE_SCREEN] = [self::SLOT_SCREEN];
            $routing[self::SLOT_SCREEN] = [];
        }

        if ($usesEndpoint) {
            $flowJson['data_api_version'] = self::DATA_API_VERSION;
            $flowJson['routing_model'] = $routing;
        }

        $flowJson['screens'] = $compiled;

        return new CompiledFlow($flowJson, $mappings);
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

    /**
     * @param  bool  $viaEndpoint  la pantalla envía sus respuestas al CRM; si no, cierra el formulario
     * @param  bool  $terminal  algún camino termina el formulario en esta pantalla
     */
    private function compileScreen(array $screen, int $index, bool $viaEndpoint, bool $terminal): array
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

        $action = $viaEndpoint
            ? ['name' => 'data_exchange', 'payload' => ['crm_action' => self::SUBMIT_ACTION] + $answers]
            : ['name' => 'complete', 'payload' => $answers === [] ? new \stdClass : $answers];
        $children[] = $this->footer($screen['footer_label'], $action);

        $compiled = ['id' => self::screenId($index), 'title' => $screen['title']];

        if ($terminal) {
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

        if ($type === 'image') {
            return $this->image($block);
        }

        if ($type === 'image_carousel') {
            return $this->carousel($block);
        }

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
            $component['data-source'] = array_map($this->option(...), $block['options']);

            $withImages = array_filter($block['options'], fn (array $option) => ! empty($option['image'])) !== [];
            if ($withImages && $type !== 'dropdown' && ! empty($block['media_size'])) {
                $component['media-size'] = $block['media_size'];
            }
        }

        if (in_array($type, self::RESTORABLE_TYPES, true)) {
            $component['init-value'] = '${data.'.$block['key'].'}';
        }

        return $component;
    }

    /** Ancho completo de la pantalla, con el alto elegido y la proporción real de la imagen. */
    private function image(array $block): array
    {
        $component = [
            'type' => 'Image',
            'src' => $block['src'],
            'height' => FlowDefinitionValidator::IMAGE_SIZES[$block['size']],
            'scale-type' => $block['scale_type'],
            'aspect-ratio' => FlowImage::decode($block['src'])?->aspectRatio() ?? 1,
        ];

        if (! empty($block['alt_text'])) {
            $component['alt-text'] = $block['alt_text'];
        }

        return $component;
    }

    private function carousel(array $block): array
    {
        return [
            'type' => 'ImageCarousel',
            'aspect-ratio' => $block['aspect_ratio'],
            'scale-type' => $block['scale_type'],
            'images' => array_map(
                fn (array $image) => ['src' => $image['src'], 'alt-text' => $image['alt_text']],
                $block['images'],
            ),
        ];
    }

    private function option(array $option): array
    {
        $compiled = ['id' => (string) $option['id'], 'title' => (string) $option['title']];

        if (! empty($option['image'])) {
            $compiled['image'] = $option['image'];

            if (! empty($option['alt_text'])) {
                $compiled['alt-text'] = $option['alt_text'];
            }
        }

        return $compiled;
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

    /**
     * Rutas de una pantalla para el routing_model: sus pantallas destino y, si
     * algún camino termina con turno activo, la pantalla de fecha.
     *
     * @param  list<?int>  $destinations
     * @return list<string>
     */
    private function screenRoutes(array $destinations, bool $withAppointment): array
    {
        $routes = [];

        foreach ($destinations as $destination) {
            if ($destination !== null) {
                $routes[] = self::screenId($destination);
            } elseif ($withAppointment) {
                $routes[] = self::DATE_SCREEN;
            }
        }

        return $routes;
    }

    private function hasMappedQuestion(array $screen): bool
    {
        foreach ($screen['blocks'] as $block) {
            if ($this->isQuestion($block) && ! empty($block['maps_to'])) {
                return true;
            }
        }

        return false;
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
