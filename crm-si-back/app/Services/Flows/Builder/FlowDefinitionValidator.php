<?php

namespace App\Services\Flows\Builder;

use App\Enums\ContactFieldType;
use App\Models\AiSchedulingConfig;
use App\Models\ContactField;
use Illuminate\Support\Collection;

/**
 * Valida la definición del constructor visual de formularios (`schema: 1`)
 * contra los límites de componentes de Meta y los campos del contacto.
 */
class FlowDefinitionValidator
{
    public const MAX_SCREENS = 10;

    /**
     * Claves que usa el endpoint o Meta en los datos de pantalla. Las
     * respuestas de texto también son datos de pantalla (init-value), por eso
     * no pueden empezar con `required` (Meta lo prohíbe).
     */
    public const RESERVED_KEYS = ['crm_action', 'next_screen', 'flow_token', 'date', 'slot', 'error_message'];

    public const TEXT_TYPES = ['heading' => 80, 'subheading' => 80, 'body' => 4096, 'caption' => 409];

    /** Tipo de bloque de pregunta → largo máximo del label. */
    public const QUESTION_TYPES = [
        'short_text' => 20,
        'long_text' => 20,
        'dropdown' => 20,
        'single_choice' => 30,
        'multiple_choice' => 30,
        'date' => 40,
        'opt_in' => 120,
    ];

    /** Tipo de bloque de opciones → cantidad máxima de opciones. */
    public const CHOICE_TYPES = ['dropdown' => 200, 'single_choice' => 20, 'multiple_choice' => 20];

    public const INPUT_TYPES = ['text', 'email', 'number', 'phone'];

    private const FOOTER_MAX = 35;

    private const HELPER_MAX = 80;

    private const OPTION_TITLE_MAX = 30;

    private const MAX_OPT_INS_PER_SCREEN = 5;

    private const PREFILL_BASE = ['contact_name', 'contact_email', 'contact_phone'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var Collection<int, ContactField> */
    private Collection $fields;

    /**
     * @return array<string, list<string>> errores por ruta (p. ej. `screens.0.blocks.2.label`)
     */
    public function validate(array $definition, int $tenantId): array
    {
        $this->errors = [];
        $this->fields = ContactField::forTenant($tenantId);

        if (($definition['schema'] ?? null) !== 1) {
            $this->add('schema', 'Versión de formulario no soportada.');
        }

        $screens = $definition['screens'] ?? null;

        if (! is_array($screens) || ! array_is_list($screens) || $screens === [] || count($screens) > self::MAX_SCREENS) {
            $this->add('screens', 'El formulario debe tener entre 1 y '.self::MAX_SCREENS.' pantallas.');

            return $this->errors;
        }

        $keys = [];
        $hasQuestion = false;

        foreach ($screens as $i => $screen) {
            $this->validateScreen(is_array($screen) ? $screen : [], "screens.{$i}", $keys, $hasQuestion);
        }

        if (! $hasQuestion) {
            $this->add('screens', 'El formulario necesita al menos una pregunta.');
        }

        $appointment = $definition['appointment'] ?? null;

        if ($appointment !== null) {
            $this->validateAppointment(is_array($appointment) ? $appointment : [], $keys, $tenantId);
        }

        return $this->errors;
    }

    /**
     * @param  array<string, string>  $keys  key → tipo de bloque, acumulado en todo el formulario
     */
    private function validateScreen(array $screen, string $path, array &$keys, bool &$hasQuestion): void
    {
        if (! $this->filledString($screen['title'] ?? null)) {
            $this->add("{$path}.title", 'La pantalla necesita un título.');
        }

        if (! $this->stringWithin($screen['footer_label'] ?? null, self::FOOTER_MAX)) {
            $this->add("{$path}.footer_label", 'El texto del botón debe tener entre 1 y '.self::FOOTER_MAX.' caracteres.');
        }

        $blocks = $screen['blocks'] ?? null;

        if (! is_array($blocks) || ! array_is_list($blocks) || $blocks === []) {
            $this->add("{$path}.blocks", 'La pantalla necesita al menos un bloque.');

            return;
        }

        $optIns = 0;

        foreach ($blocks as $j => $block) {
            $block = is_array($block) ? $block : [];
            $blockPath = "{$path}.blocks.{$j}";
            $type = $block['type'] ?? null;

            if (is_string($type) && isset(self::TEXT_TYPES[$type])) {
                if (! $this->stringWithin($block['text'] ?? null, self::TEXT_TYPES[$type])) {
                    $this->add("{$blockPath}.text", 'El texto debe tener entre 1 y '.self::TEXT_TYPES[$type].' caracteres.');
                }

                continue;
            }

            if (! is_string($type) || ! isset(self::QUESTION_TYPES[$type])) {
                $this->add("{$blockPath}.type", 'Tipo de bloque desconocido.');

                continue;
            }

            $hasQuestion = true;
            $optIns += $type === 'opt_in' ? 1 : 0;
            $this->validateQuestion($block, $type, $blockPath, $keys);
        }

        if ($optIns > self::MAX_OPT_INS_PER_SCREEN) {
            $this->add("{$path}.blocks", 'Una pantalla admite como máximo '.self::MAX_OPT_INS_PER_SCREEN.' casillas de aceptación.');
        }
    }

    private function validateQuestion(array $block, string $type, string $path, array &$keys): void
    {
        $this->validateKey($block['key'] ?? null, $type, $path, $keys);

        $labelMax = self::QUESTION_TYPES[$type];
        if (! $this->stringWithin($block['label'] ?? null, $labelMax)) {
            $this->add("{$path}.label", "La etiqueta debe tener entre 1 y {$labelMax} caracteres.");
        }

        if (array_key_exists('required', $block) && ! is_bool($block['required'])) {
            $this->add("{$path}.required", 'Valor inválido.');
        }

        if ($type === 'short_text' && ! in_array($block['input_type'] ?? null, self::INPUT_TYPES, true)) {
            $this->add("{$path}.input_type", 'Tipo de dato inválido.');
        }

        $helper = $block['helper_text'] ?? null;
        if (in_array($type, ['short_text', 'long_text', 'date'], true) && $helper !== null
            && (! is_string($helper) || mb_strlen($helper) > self::HELPER_MAX)) {
            $this->add("{$path}.helper_text", 'La ayuda admite hasta '.self::HELPER_MAX.' caracteres.');
        }

        if (isset(self::CHOICE_TYPES[$type])) {
            $this->validateOptions($block['options'] ?? null, self::CHOICE_TYPES[$type], "{$path}.options");
        }

        $prefill = $block['prefill'] ?? null;
        if ($prefill !== null) {
            $this->validatePrefill($prefill, $type, "{$path}.prefill");
        }

        $mapsTo = $block['maps_to'] ?? null;
        if ($mapsTo !== null) {
            $this->validateMapping($block, $type, $mapsTo, $path);
        }
    }

    private function validateKey(mixed $key, string $type, string $path, array &$keys): void
    {
        if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
            $this->add("{$path}.key", 'El nombre interno debe empezar con una letra minúscula y usar sólo minúsculas, números y guiones bajos (máx. 40).');

            return;
        }

        if (in_array($key, self::RESERVED_KEYS, true) || str_starts_with($key, 'appointment_')) {
            $this->add("{$path}.key", "El nombre interno «{$key}» está reservado.");

            return;
        }

        if (str_starts_with($key, 'required')) {
            $this->add("{$path}.key", 'El nombre interno no puede empezar con «required».');

            return;
        }

        if (isset($keys[$key])) {
            $this->add("{$path}.key", "El nombre interno «{$key}» ya se usa en otra pregunta.");

            return;
        }

        $keys[$key] = $type;
    }

    private function validateOptions(mixed $options, int $max, string $path): void
    {
        if (! is_array($options) || ! array_is_list($options) || $options === [] || count($options) > $max) {
            $this->add($path, "La pregunta debe tener entre 1 y {$max} opciones.");

            return;
        }

        $ids = [];

        foreach ($options as $k => $option) {
            $id = $option['id'] ?? null;

            if (! $this->filledString($id)) {
                $this->add("{$path}.{$k}.id", 'La opción necesita un identificador.');
            } elseif (isset($ids[$id])) {
                $this->add("{$path}.{$k}.id", 'Hay opciones repetidas.');
            } else {
                $ids[$id] = true;
            }

            if (! $this->stringWithin($option['title'] ?? null, self::OPTION_TITLE_MAX)) {
                $this->add("{$path}.{$k}.title", 'La opción debe tener entre 1 y '.self::OPTION_TITLE_MAX.' caracteres.');
            }
        }
    }

    private function validatePrefill(mixed $prefill, string $type, string $path): void
    {
        if (! in_array($type, ['short_text', 'long_text'], true)) {
            $this->add($path, 'Este tipo de pregunta no admite precarga.');

            return;
        }

        $valid = is_string($prefill) && (in_array($prefill, self::PREFILL_BASE, true)
            || (str_starts_with($prefill, 'cf_') && $this->field(substr($prefill, 3)) !== null));

        if (! $valid) {
            $this->add($path, 'La precarga elegida no existe.');
        }
    }

    private function validateMapping(array $block, string $type, mixed $target, string $path): void
    {
        $inputType = $block['input_type'] ?? 'text';
        $mapPath = "{$path}.maps_to";

        if ($target === 'contact.name') {
            if ($type !== 'short_text') {
                $this->add($mapPath, 'El nombre sólo se puede guardar desde un texto corto.');
            }

            return;
        }

        if ($target === 'contact.email') {
            if ($type !== 'short_text' || $inputType !== 'email') {
                $this->add($mapPath, 'El email sólo se puede guardar desde un texto corto de tipo email.');
            }

            return;
        }

        if (! is_string($target) || ! str_starts_with($target, 'custom_data.')) {
            $this->add($mapPath, 'Destino inválido.');

            return;
        }

        $key = substr($target, strlen('custom_data.'));
        $field = $this->field($key);

        if ($field === null) {
            $this->add($mapPath, "El campo «{$key}» no existe.");

            return;
        }

        if (! $this->compatible($field->type, $type, $inputType)) {
            $this->add($mapPath, "La pregunta no es compatible con el campo «{$field->label}».");

            return;
        }

        if (in_array($field->type, [ContactFieldType::Select, ContactFieldType::MultiSelect], true)
            && ! $this->optionsMatchChoices($block['options'] ?? [], $field->options['choices'] ?? [])) {
            $this->add("{$path}.options", "Las opciones tienen que ser las del campo «{$field->label}».");
        }

        if ($field->is_required && ($block['required'] ?? false) !== true) {
            $this->add("{$path}.required", "El campo «{$field->label}» es obligatorio en el CRM: la pregunta tiene que ser obligatoria.");
        }
    }

    private function compatible(ContactFieldType $fieldType, string $type, string $inputType): bool
    {
        return match ($fieldType) {
            ContactFieldType::Text => in_array($type, ['short_text', 'long_text', 'dropdown', 'single_choice'], true),
            ContactFieldType::Email => $type === 'short_text' && $inputType === 'email',
            ContactFieldType::Phone => $type === 'short_text' && $inputType === 'phone',
            ContactFieldType::Url => $type === 'short_text',
            ContactFieldType::Number => $type === 'short_text' && $inputType === 'number',
            ContactFieldType::Date => $type === 'date',
            ContactFieldType::Boolean => $type === 'opt_in',
            ContactFieldType::Select => in_array($type, ['dropdown', 'single_choice'], true),
            ContactFieldType::MultiSelect => $type === 'multiple_choice',
            default => false,
        };
    }

    private function optionsMatchChoices(mixed $options, mixed $choices): bool
    {
        if (! is_array($options) || ! is_array($choices)) {
            return false;
        }

        $actual = array_map(fn ($o) => [(string) ($o['id'] ?? ''), (string) ($o['title'] ?? '')], $options);
        $expected = array_map(fn ($c) => [(string) $c, (string) $c], array_values($choices));
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    private function validateAppointment(array $appointment, array $keys, int $tenantId): void
    {
        $limits = ['date_label' => 20, 'slot_label' => 20, 'confirm_label' => 35];

        foreach ($limits as $field => $max) {
            if (! $this->stringWithin($appointment[$field] ?? null, $max)) {
                $this->add("appointment.{$field}", "Debe tener entre 1 y {$max} caracteres.");
            }
        }

        foreach (['date_screen_title', 'slot_screen_title'] as $field) {
            if (! $this->filledString($appointment[$field] ?? null)) {
                $this->add("appointment.{$field}", 'La pantalla necesita un título.');
            }
        }

        $reasonKey = $appointment['reason_key'] ?? null;
        if ($reasonKey !== null && (! is_string($reasonKey) || ($keys[$reasonKey] ?? null) !== 'long_text')) {
            $this->add('appointment.reason_key', 'El motivo tiene que ser una pregunta de texto largo.');
        }

        $schedulingEnabled = AiSchedulingConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('enabled', true)
            ->exists();

        if (! $schedulingEnabled) {
            $this->add('appointment', 'Para reservar turnos primero activá la agenda de turnos del asistente.');
        }
    }

    private function field(string $key): ?ContactField
    {
        return $this->fields->firstWhere('key', $key);
    }

    private function filledString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function stringWithin(mixed $value, int $max): bool
    {
        return $this->filledString($value) && mb_strlen($value) <= $max;
    }

    private function add(string $path, string $message): void
    {
        $this->errors[$path][] = $message;
    }
}
