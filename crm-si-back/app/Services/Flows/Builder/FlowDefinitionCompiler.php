<?php

namespace App\Services\Flows\Builder;

/**
 * Compila la definición del constructor visual a Flow JSON de Meta.
 * Asume una definición ya validada por FlowDefinitionValidator.
 *
 * Las claves que usa el endpoint (`crm_action`, `next_screen`, `date`,
 * `slot`, `appointment_*`) y los nombres de datos de las pantallas de turno
 * (`available_dates`, `available_slots`, `selected_date`) son el contrato con
 * FlowEndpointHandler.
 */
class FlowDefinitionCompiler
{
    public const FLOW_JSON_VERSION = '7.3';

    public const DATA_API_VERSION = '4.0';

    public const DATE_SCREEN = 'TURNO_FECHA';

    public const SLOT_SCREEN = 'TURNO_HORARIO';

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

    /** @var array<string, int> key de pregunta → índice de pantalla */
    private array $questionScreens = [];

    public function compile(array $definition): CompiledFlow
    {
        $screens = $definition['screens'];
        $appointment = $definition['appointment'] ?? null;
        $this->questionScreens = [];
        $prefillKeys = [];
        $mappings = [];

        foreach ($screens as $i => $screen) {
            foreach ($screen['blocks'] as $block) {
                if (! $this->isQuestion($block)) {
                    continue;
                }

                $this->questionScreens[$block['key']] = $i;

                if (! empty($block['prefill'])) {
                    $prefillKeys[$block['prefill']] = true;
                }

                if (! empty($block['maps_to'])) {
                    $mappings[] = ['response_key' => $block['key'], 'target' => $block['maps_to']];
                }
            }
        }

        $compiled = [];
        $last = count($screens) - 1;

        foreach ($screens as $i => $screen) {
            $compiled[] = $this->compileScreen($screen, $i, $i === $last, $appointment, array_keys($prefillKeys));
        }

        $flowJson = ['version' => self::FLOW_JSON_VERSION];

        if ($appointment !== null) {
            $compiled[] = $this->dateScreen($appointment);
            $compiled[] = $this->slotScreen($appointment, $screens);
            $flowJson['data_api_version'] = self::DATA_API_VERSION;
            $flowJson['routing_model'] = $this->routingModel(count($screens));
        }

        $flowJson['screens'] = $compiled;

        return new CompiledFlow($flowJson, $mappings);
    }

    public static function screenId(int $index): string
    {
        // Meta sólo admite letras y guiones bajos en el id de pantalla.
        return 'PANTALLA_'.chr(ord('A') + $index);
    }

    /** @param  list<string>  $prefillKeys */
    private function compileScreen(array $screen, int $index, bool $isLast, ?array $appointment, array $prefillKeys): array
    {
        $children = array_map(fn (array $block) => $this->compileBlock($block, $index), $screen['blocks']);
        $children[] = $this->footer($screen['footer_label'], $this->footerAction($index, $isLast, $appointment));

        $compiled = ['id' => self::screenId($index), 'title' => $screen['title']];

        if ($isLast && $appointment === null) {
            $compiled['terminal'] = true;
            $compiled['success'] = true;
        }

        if ($index === 0 && $prefillKeys !== []) {
            $compiled['data'] = array_fill_keys($prefillKeys, ['type' => 'string', '__example__' => '']);
        }

        $compiled['layout'] = ['type' => 'SingleColumnLayout', 'children' => $children];

        return $compiled;
    }

    private function footerAction(int $index, bool $isLast, ?array $appointment): array
    {
        if (! $isLast) {
            return [
                'name' => 'navigate',
                'next' => ['type' => 'screen', 'name' => self::screenId($index + 1)],
                'payload' => new \stdClass,
            ];
        }

        if ($appointment === null) {
            return ['name' => 'complete', 'payload' => $this->answersPayload($index)];
        }

        return [
            'name' => 'data_exchange',
            'payload' => ['crm_action' => 'appointment_dates', 'next_screen' => self::DATE_SCREEN] + $this->answersPayload($index),
        ];
    }

    private function compileBlock(array $block, int $screenIndex): array
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

        if (! empty($block['prefill'])) {
            $component['init-value'] = $screenIndex === 0
                ? '${data.'.$block['prefill'].'}'
                : '${screen.'.self::screenId(0).'.data.'.$block['prefill'].'}';
        }

        return $component;
    }

    /**
     * Payload con todas las respuestas del formulario, referenciadas desde la
     * pantalla $fromScreen (o desde una pantalla de turno si es null).
     *
     * @return array<string, string>
     */
    private function answersPayload(?int $fromScreen): array
    {
        $payload = [];

        foreach (array_keys($this->questionScreens) as $key) {
            $payload[$key] = $this->answerRef($key, $fromScreen);
        }

        return $payload;
    }

    private function answerRef(string $key, ?int $fromScreen): string
    {
        $screen = $this->questionScreens[$key];

        return $screen === $fromScreen
            ? '${form.'.$key.'}'
            : '${screen.'.self::screenId($screen).'.form.'.$key.'}';
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

    private function slotScreen(array $appointment, array $screens): array
    {
        $payload = ['crm_action' => 'book_appointment', 'slot' => '${form.slot}'];
        $emailKey = $this->keyMappedTo($screens, 'contact.email');

        if ($emailKey !== null) {
            $payload['appointment_email'] = $this->answerRef($emailKey, null);
        }

        if (! empty($appointment['reason_key'])) {
            $payload['appointment_reason'] = $this->answerRef($appointment['reason_key'], null);
        }

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
                    $this->footer($appointment['confirm_label'], ['name' => 'data_exchange', 'payload' => $payload]),
                ],
            ],
        ];
    }

    /** @return array<string, list<string>> */
    private function routingModel(int $screenCount): array
    {
        $routes = [];

        for ($i = 0; $i < $screenCount; $i++) {
            $routes[self::screenId($i)] = [$i === $screenCount - 1 ? self::DATE_SCREEN : self::screenId($i + 1)];
        }

        $routes[self::DATE_SCREEN] = [self::SLOT_SCREEN];
        $routes[self::SLOT_SCREEN] = [];

        return $routes;
    }

    private function keyMappedTo(array $screens, string $target): ?string
    {
        foreach ($screens as $screen) {
            foreach ($screen['blocks'] as $block) {
                if (($block['maps_to'] ?? null) === $target) {
                    return $block['key'];
                }
            }
        }

        return null;
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
