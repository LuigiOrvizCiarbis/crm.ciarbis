<?php

namespace App\Services\Flows\Builder;

use App\Enums\ContactFieldType;
use App\Models\AiSchedulingConfig;
use App\Models\ContactField;
use App\Support\RepeaterFieldSchema;
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

    /** Bloques de imagen: sólo se muestran, no son preguntas. */
    public const MEDIA_TYPES = ['image', 'image_carousel'];

    /** Tamaño de un bloque de imagen → alto en el formulario. */
    public const IMAGE_SIZES = ['small' => 120, 'medium' => 200, 'large' => 300];

    public const SCALE_TYPES = ['contain', 'cover'];

    public const CAROUSEL_RATIOS = ['4:3', '16:9'];

    /** Tamaño de las imágenes de las opciones (sólo opción única y múltiple). */
    public const MEDIA_SIZES = ['regular', 'large'];

    /** Meta recomienda hasta 300 KB por imagen; en las opciones admite 100 KB desde Flow JSON 6.0. */
    public const IMAGE_MAX_BYTES = 300_000;

    public const OPTION_IMAGE_MAX_BYTES = 100_000;

    /**
     * Tope propio: las imágenes viajan en base64 en el pedido del constructor
     * (el proxy de Next corta a los 10 MB) y en el Flow JSON que se sube a Meta.
     */
    public const TOTAL_IMAGE_MAX_BYTES = 6_000_000;

    private const FOOTER_MAX = 35;

    private const HELPER_MAX = 80;

    private const OPTION_TITLE_MAX = 30;

    private const MAX_OPT_INS_PER_SCREEN = 5;

    private const MAX_IMAGES_PER_SCREEN = 3;

    private const MAX_CAROUSELS_PER_SCREEN = 2;

    private const MAX_CAROUSELS = 3;

    private const CAROUSEL_IMAGES_MAX = 3;

    private const DROPDOWN_WITH_IMAGES_MAX = 100;

    private const ALT_TEXT_MAX = 100;

    private const PREFILL_BASE = ['contact_name', 'contact_email', 'contact_phone'];

    /** Las filas siguientes de un grupo agregan `-<n>` (hasta `-10`) al id. */
    private const GROUP_ID_MAX = 37;

    /** Meta admite 50 componentes por pantalla; una fila suma la pregunta de "agregar otro" y el botón. */
    private const GROUP_FIELDS_MAX = 20;

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var Collection<int, ContactField> */
    private Collection $fields;

    /** Bytes de todas las imágenes del formulario. */
    private int $imageBytes = 0;

    private int $carousels = 0;

    /**
     * @return array<string, list<string>> errores por ruta (p. ej. `screens.0.blocks.2.label`)
     */
    public function validate(array $definition, int $tenantId): array
    {
        $this->errors = [];
        $this->fields = ContactField::forTenant($tenantId);
        $this->imageBytes = 0;
        $this->carousels = 0;

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
            if (FlowDefinitionExpander::isRepeater($screen)) {
                $this->validateRepeater($screen, "screens.{$i}", $keys);
                $hasQuestion = true;
            } else {
                $this->validateScreen(is_array($screen) ? $screen : [], "screens.{$i}", $keys, $hasQuestion);
            }
        }

        if (! $hasQuestion) {
            $this->add('screens', 'El formulario necesita al menos una pregunta.');
        }

        $generated = FlowDefinitionExpander::screenCount($definition);
        if ($generated > self::MAX_SCREENS) {
            $this->add('screens', "El formulario genera {$generated} pantallas y Meta admite ".self::MAX_SCREENS.'. Bajá la cantidad de filas de los grupos repetibles.');
        }

        $this->validateGroupKeys($keys);

        if ($this->carousels > self::MAX_CAROUSELS) {
            $this->add('screens', 'El formulario admite como máximo '.self::MAX_CAROUSELS.' carruseles de imágenes.');
        }

        if ($this->imageBytes > self::TOTAL_IMAGE_MAX_BYTES) {
            $this->add('screens', 'Las imágenes del formulario superan los '.intdiv(self::TOTAL_IMAGE_MAX_BYTES, 1_000_000).' MB en total. Usá menos imágenes o más livianas.');
        }

        // Los caminos se validan sobre pantallas y bloques bien formados: con
        // datos de otro tipo (bloques que no son lista, ids que no son texto)
        // recorrerlos fallaría en vez de devolver los errores ya encontrados.
        if ($this->errors === []) {
            $this->validateRoutes($screens);
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
        $images = 0;
        $carousels = 0;

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

            if ($type === 'image') {
                $images++;
                $this->validateImageBlock($block, $blockPath);

                continue;
            }

            if ($type === 'image_carousel') {
                $carousels++;
                $this->validateCarousel($block, $blockPath);

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

        if ($images > self::MAX_IMAGES_PER_SCREEN) {
            $this->add("{$path}.blocks", 'Una pantalla admite como máximo '.self::MAX_IMAGES_PER_SCREEN.' imágenes.');
        }

        if ($carousels > self::MAX_CAROUSELS_PER_SCREEN) {
            $this->add("{$path}.blocks", 'Una pantalla admite como máximo '.self::MAX_CAROUSELS_PER_SCREEN.' carruseles.');
        }

        $this->carousels += $carousels;
    }

    private function validateImageBlock(array $block, string $path): void
    {
        $this->validateImage($block['src'] ?? null, self::IMAGE_MAX_BYTES, "{$path}.src");
        $this->validateAltText($block['alt_text'] ?? null, false, "{$path}.alt_text");

        if (! is_string($block['size'] ?? null) || ! isset(self::IMAGE_SIZES[$block['size']])) {
            $this->add("{$path}.size", 'Tamaño inválido.');
        }

        if (! in_array($block['scale_type'] ?? null, self::SCALE_TYPES, true)) {
            $this->add("{$path}.scale_type", 'Ajuste inválido.');
        }
    }

    private function validateCarousel(array $block, string $path): void
    {
        $images = $block['images'] ?? null;

        if (! is_array($images) || ! array_is_list($images) || $images === [] || count($images) > self::CAROUSEL_IMAGES_MAX) {
            $this->add("{$path}.images", 'El carrusel necesita entre 1 y '.self::CAROUSEL_IMAGES_MAX.' imágenes.');
        } else {
            foreach ($images as $k => $image) {
                $image = is_array($image) ? $image : [];
                $this->validateImage($image['src'] ?? null, self::IMAGE_MAX_BYTES, "{$path}.images.{$k}.src");
                // Meta exige el texto alternativo en cada imagen del carrusel.
                $this->validateAltText($image['alt_text'] ?? null, true, "{$path}.images.{$k}.alt_text");
            }
        }

        if (! in_array($block['aspect_ratio'] ?? null, self::CAROUSEL_RATIOS, true)) {
            $this->add("{$path}.aspect_ratio", 'Proporción inválida.');
        }

        if (! in_array($block['scale_type'] ?? null, self::SCALE_TYPES, true)) {
            $this->add("{$path}.scale_type", 'Ajuste inválido.');
        }
    }

    private function validateImage(mixed $src, int $maxBytes, string $path): void
    {
        $image = FlowImage::decode($src);

        if ($image === null) {
            $this->add($path, 'Subí una imagen JPEG o PNG.');

            return;
        }

        $this->imageBytes += $image->bytes;

        if ($image->bytes > $maxBytes) {
            $this->add($path, 'La imagen pesa más de '.intdiv($maxBytes, 1000).' KB.');
        }
    }

    private function validateAltText(mixed $alt, bool $required, string $path): void
    {
        if ($alt === null || $alt === '') {
            if ($required) {
                $this->add($path, 'Describí la imagen.');
            }

            return;
        }

        if (! is_string($alt) || mb_strlen($alt) > self::ALT_TEXT_MAX) {
            $this->add($path, 'La descripción admite hasta '.self::ALT_TEXT_MAX.' caracteres.');
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
            $this->validateOptions($block['options'] ?? null, $type, "{$path}.options");
        }

        $mediaSize = $block['media_size'] ?? null;
        if ($mediaSize !== null && (! in_array($type, ['single_choice', 'multiple_choice'], true) || ! in_array($mediaSize, self::MEDIA_SIZES, true))) {
            $this->add("{$path}.media_size", 'Tamaño de imagen inválido.');
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

    private function validateOptions(mixed $options, string $type, string $path): void
    {
        $withImages = $type === 'dropdown' && is_array($options)
            && array_filter($options, fn ($option) => is_array($option) && ($option['image'] ?? null) !== null) !== [];
        // Meta admite 200 opciones en una lista, pero 100 si alguna tiene imagen.
        $max = $withImages ? self::DROPDOWN_WITH_IMAGES_MAX : self::CHOICE_TYPES[$type];

        if (! is_array($options) || ! array_is_list($options) || $options === [] || count($options) > $max) {
            $this->add($path, "La pregunta debe tener entre 1 y {$max} opciones.");

            return;
        }

        $ids = [];

        foreach ($options as $k => $option) {
            $option = is_array($option) ? $option : [];
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

            if (($option['image'] ?? null) !== null) {
                $this->validateImage($option['image'], self::OPTION_IMAGE_MAX_BYTES, "{$path}.{$k}.image");
                $this->validateAltText($option['alt_text'] ?? null, false, "{$path}.{$k}.alt_text");
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

        foreach ($options as $option) {
            if (! is_array($option) || ! is_string($option['id'] ?? null) || ! is_string($option['title'] ?? null)) {
                return false;
            }
        }

        $actual = array_map(fn ($o) => [(string) ($o['id'] ?? ''), (string) ($o['title'] ?? '')], $options);
        $expected = array_map(fn ($c) => [(string) $c, (string) $c], array_values($choices));
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }

    /**
     * Grupo repetible: ligado a un campo repeater del contacto, con una copia
     * de sus subcampos que tiene que coincidir con el CRM (el formulario se
     * genera de esa copia). Los límites son los de los bloques que genera.
     */
    private function validateRepeater(array $group, string $path, array &$keys): void
    {
        $this->validateKey($group['key'] ?? null, FlowDefinitionExpander::REPEATER, $path, $keys);

        $id = $group['id'] ?? null;
        if (is_string($id) && mb_strlen($id) > self::GROUP_ID_MAX) {
            $this->add("{$path}.id", 'Identificador de pantalla demasiado largo.');
        }

        foreach (['title' => 'El grupo necesita un título.', 'row_title' => 'Indicá el título de cada fila.'] as $attribute => $message) {
            if (! $this->filledString($group[$attribute] ?? null)) {
                $this->add("{$path}.{$attribute}", $message);
            }
        }

        if (! $this->stringWithin($group['footer_label'] ?? null, self::FOOTER_MAX)) {
            $this->add("{$path}.footer_label", 'El texto del botón debe tener entre 1 y '.self::FOOTER_MAX.' caracteres.');
        }

        if (! $this->stringWithin($group['more_label'] ?? null, self::QUESTION_TYPES['single_choice'])) {
            $this->add("{$path}.more_label", 'La pregunta debe tener entre 1 y '.self::QUESTION_TYPES['single_choice'].' caracteres.');
        }

        $fieldKey = $group['field_key'] ?? null;
        $field = is_string($fieldKey) ? $this->field($fieldKey) : null;

        if ($field === null || $field->type !== ContactFieldType::Repeater) {
            $this->add("{$path}.field_key", 'Elegí un campo de tipo lista (repeater) del contacto.');

            return;
        }

        $minRows = (int) ($field->options['min_items'] ?? RepeaterFieldSchema::DEFAULT_MIN_ITEMS);
        $maxItems = (int) ($field->options['max_items'] ?? RepeaterFieldSchema::DEFAULT_MAX_ITEMS);

        if (! $this->snapshotMatches($group, $field, $minRows)) {
            $this->add("{$path}.fields", "El campo «{$field->label}» cambió en el CRM: actualizá el grupo.");

            return;
        }

        $maxRows = $group['max_rows'] ?? null;
        $minAllowed = max($minRows, 1);
        if (! is_int($maxRows) || $maxRows < $minAllowed || $maxRows > $maxItems) {
            $this->add("{$path}.max_rows", "La cantidad de filas debe estar entre {$minAllowed} y {$maxItems}.");
        }

        if (($group['ask_first'] ?? false) === true) {
            if ($minRows > 0) {
                $this->add("{$path}.ask_first", "El campo «{$field->label}» exige al menos {$minRows} fila(s): no se puede preguntar antes.");
            } elseif (! $this->stringWithin($group['ask_first_label'] ?? null, self::QUESTION_TYPES['single_choice'])) {
                $this->add("{$path}.ask_first_label", 'La pregunta debe tener entre 1 y '.self::QUESTION_TYPES['single_choice'].' caracteres.');
            }
        }

        $optIns = 0;

        foreach ($group['fields'] as $i => $subfield) {
            $type = (string) $subfield['type'];
            $choices = $subfield['choices'] ?? [];
            $labelMax = match ($type) {
                'date' => self::QUESTION_TYPES['date'],
                'boolean' => self::QUESTION_TYPES['opt_in'],
                'select' => count($choices) > self::CHOICE_TYPES['single_choice'] ? self::QUESTION_TYPES['dropdown'] : self::QUESTION_TYPES['single_choice'],
                default => self::QUESTION_TYPES['short_text'],
            };

            if (! $this->stringWithin($subfield['label'] ?? null, $labelMax)) {
                $this->add("{$path}.fields.{$i}.label", "La etiqueta debe tener entre 1 y {$labelMax} caracteres.");
            }

            if ($type === 'select') {
                $tooLong = array_filter($choices, fn ($choice) => mb_strlen((string) $choice) > self::OPTION_TITLE_MAX);

                if ($choices === [] || count($choices) > self::CHOICE_TYPES['dropdown'] || $tooLong !== []) {
                    $this->add("{$path}.fields.{$i}.choices", 'Las opciones deben ser entre 1 y '.self::CHOICE_TYPES['dropdown'].', de hasta '.self::OPTION_TITLE_MAX.' caracteres cada una (se cambian en el CRM).');
                }
            }

            $optIns += $type === 'boolean' ? 1 : 0;
        }

        if ($optIns > self::MAX_OPT_INS_PER_SCREEN) {
            $this->add("{$path}.fields", 'Cada fila admite como máximo '.self::MAX_OPT_INS_PER_SCREEN.' datos de Sí/No.');
        }
    }

    /**
     * La copia de los subcampos tiene que ser la de los subcampos activos del
     * campo, en el mismo orden: clave, tipo, obligatorio y opciones.
     */
    private function snapshotMatches(array $group, ContactField $field, int $minRows): bool
    {
        $snapshot = $group['fields'] ?? null;

        if (! is_array($snapshot) || ! array_is_list($snapshot) || $snapshot === [] || count($snapshot) > self::GROUP_FIELDS_MAX
            || (int) ($group['min_rows'] ?? -1) !== $minRows) {
            return false;
        }

        $expected = [];
        foreach ($field->options['fields'] ?? [] as $subfield) {
            if (is_array($subfield) && ($subfield['is_active'] ?? true) && is_string($subfield['key'] ?? null)) {
                $expected[] = $this->subfieldSignature($subfield['key'], $subfield['type'] ?? null, $subfield['is_required'] ?? false, $subfield['options']['choices'] ?? []);
            }
        }

        $actual = [];
        foreach ($snapshot as $subfield) {
            if (! is_array($subfield) || ! is_string($subfield['key'] ?? null)) {
                return false;
            }

            $actual[] = $this->subfieldSignature($subfield['key'], $subfield['type'] ?? null, $subfield['required'] ?? false, $subfield['choices'] ?? []);
        }

        return $actual === $expected;
    }

    private function subfieldSignature(string $key, mixed $type, mixed $required, mixed $choices): array
    {
        return [$key, $type, $required === true, $type === 'select' && is_array($choices) ? array_values(array_map('strval', $choices)) : []];
    }

    /** Ninguna pregunta puede usar las claves que genera un grupo (`<grupo>_<fila>_…`). */
    private function validateGroupKeys(array $keys): void
    {
        $groups = array_keys(array_filter($keys, fn (string $type) => $type === FlowDefinitionExpander::REPEATER));

        foreach ($keys as $key => $type) {
            foreach ($groups as $group) {
                if ($type !== FlowDefinitionExpander::REPEATER && FlowDefinitionExpander::ownsKey($group, (string) $key)) {
                    $this->add('screens', "El nombre interno «{$key}» está reservado para el grupo «{$group}».");
                }
            }
        }
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

    /**
     * Ids de pantalla, destinos (`next`) y preguntas que eligen el camino
     * (`branches`). Sólo se salta hacia adelante, así no hay ciclos, que Meta
     * no admite en el routing_model.
     */
    private function validateRoutes(array $screens): void
    {
        $ids = [];

        foreach ($screens as $i => $screen) {
            $id = is_array($screen) ? ($screen['id'] ?? null) : null;

            if ($id === null) {
                continue;
            }

            if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) || isset($ids[$id])) {
                $this->add("screens.{$i}.id", 'Identificador de pantalla inválido o repetido.');
            } else {
                $ids[$id] = $i;
            }
        }

        foreach ($screens as $i => $screen) {
            if (! is_array($screen)) {
                continue;
            }

            if (array_key_exists('next', $screen)) {
                $this->validateTarget($screen['next'], $i, $ids, "screens.{$i}.next");
            }

            $branching = 0;

            foreach ($screen['blocks'] ?? [] as $j => $block) {
                if (! is_array($block) || ! isset($block['branches']) || $block['branches'] === []) {
                    continue;
                }

                $path = "screens.{$i}.blocks.{$j}.branches";
                $branching++;

                if (! in_array($block['type'] ?? null, FlowDefinitionRoutes::BRANCHING_TYPES, true) || ! is_array($block['branches'])) {
                    $this->add($path, 'Esta pregunta no puede cambiar el camino.');

                    continue;
                }

                if ($branching > 1) {
                    $this->add($path, 'Sólo una pregunta por pantalla puede cambiar el camino.');
                }

                $optionIds = array_map(fn ($option) => (string) ($option['id'] ?? ''), is_array($block['options'] ?? null) ? $block['options'] : []);

                foreach ($block['branches'] as $optionId => $target) {
                    if (! in_array((string) $optionId, $optionIds, true)) {
                        $this->add($path, 'La regla apunta a una opción que ya no existe.');
                    } else {
                        $this->validateTarget($target, $i, $ids, $path);
                    }
                }
            }
        }

        // La alcanzabilidad se recorre sobre caminos sin otros errores.
        if ($this->errors !== []) {
            return;
        }

        $reachable = FlowDefinitionRoutes::fromDefinition(['screens' => $screens])->reachable();

        foreach (array_keys($screens) as $i) {
            if ($i > 0 && ! isset($reachable[$i])) {
                $this->add("screens.{$i}", 'Ninguna respuesta lleva a esta pantalla.');
            }
        }
    }

    /** @param  array<string, int>  $ids  id de pantalla → índice */
    private function validateTarget(mixed $target, int $from, array $ids, string $path): void
    {
        if ($target === FlowDefinitionRoutes::FOLLOWING || $target === FlowDefinitionRoutes::END) {
            return;
        }

        if (! is_string($target) || ! str_starts_with($target, FlowDefinitionRoutes::SCREEN_PREFIX)) {
            $this->add($path, 'Destino inválido.');

            return;
        }

        $index = $ids[substr($target, strlen(FlowDefinitionRoutes::SCREEN_PREFIX))] ?? null;

        if ($index === null) {
            $this->add($path, 'La pantalla de destino ya no existe.');
        } elseif ($index <= $from) {
            $this->add($path, 'Sólo se puede saltar a una pantalla posterior.');
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
