<?php

namespace App\Support;

use App\Enums\ContactFieldType;
use App\Models\ContactField;
use App\Support\RepeaterFieldSchema;
use Illuminate\Database\Eloquent\Collection;

/**
 * Arma el JSON Schema con el que se le pide al modelo que extraiga datos de un
 * documento, derivado de los campos custom que definió el tenant.
 *
 * El schema viaja como input de una tool con strict, así que cada tipo debe
 * declarar explícitamente que admite null: un schema estricto rechaza null si
 * no está en la lista de tipos, y necesitamos que el modelo pueda decir "este
 * dato no está en el documento" en vez de inventarlo.
 */
class ExtractionSchemaBuilder
{
    /**
     * Campos que la IA nunca puede completar. Un File guarda el id de un
     * MediaAsset del tenant: el modelo no tiene forma de conocerlo, y aunque
     * devolviera un número sería un id ajeno o inexistente.
     */
    private const EXCLUDED_TYPES = [ContactFieldType::File];

    /**
     * Devuelve el schema para el modelo y un snapshot de los campos vigentes.
     *
     * El snapshot (key => tipo) se persiste junto a la extracción: si el tenant
     * borra un campo mientras el usuario revisa, la confirmación descarta esa
     * clave en vez de escribirla en custom_data sin validación.
     *
     * @return array{schema: array<string, mixed>, fields: array<string, string>}
     */
    public function build(int $tenantId): array
    {
        $fields = $this->fieldsForTenant($tenantId);

        $properties = [];
        $snapshot = [];

        foreach ($fields as $field) {
            if (in_array($field->type, self::EXCLUDED_TYPES, true)) {
                continue;
            }

            $properties[$field->key] = $this->propertyFor($field);
            $snapshot[$field->key] = $field->type->value;
        }

        return [
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                // Todas las claves son required, pero admiten null: así el modelo
                // se ve obligado a pronunciarse sobre cada campo en vez de omitir
                // silenciosamente los que no encontró.
                'required' => array_keys($properties),
                'additionalProperties' => false,
            ],
            'fields' => $snapshot,
        ];
    }

    /**
     * Query fresca, sin el cache estático de ContactField::forTenant().
     *
     * Ese cache vive por proceso y se invalida sólo en el proceso que hizo el
     * cambio. Un worker de cola vive horas: si el tenant edita sus campos desde
     * la app, el worker seguiría extrayendo contra el schema viejo.
     *
     * @return Collection<int, ContactField>
     */
    private function fieldsForTenant(int $tenantId): Collection
    {
        return ContactField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyFor(ContactField $field): array
    {
        $description = $this->describe($field);

        return match ($field->type) {
            ContactFieldType::Text => [
                'type' => ['string', 'null'],
                'maxLength' => 1000,
                'description' => $description,
            ],
            ContactFieldType::Number => [
                'type' => ['number', 'null'],
                'description' => $description,
            ],
            // Sin 'format': con strict, declararlo empuja al modelo a producir
            // un string con esa forma aunque el dato no esté en el documento —
            // devolvía fechas inventadas ("5210-01-01") en vez de null. El
            // formato se pide por description, que no fuerza nada. Verificado
            // contra la API.
            ContactFieldType::Date => [
                'type' => ['string', 'null'],
                'description' => $description.' Formato ISO 8601 (AAAA-MM-DD).',
            ],
            ContactFieldType::Boolean => [
                'type' => ['boolean', 'null'],
                'description' => $description,
            ],
            // anyOf en vez de type union + enum: con strict, el validador exige
            // que cada valor del enum matchee el tipo declarado, y una opción
            // como 'ARS' no matchea ['string','null'] (la API responde 400).
            ContactFieldType::Select => [
                'anyOf' => [
                    ['type' => 'string', 'enum' => $this->choices($field)],
                    ['type' => 'null'],
                ],
                'description' => $description,
            ],
            ContactFieldType::MultiSelect => [
                'type' => ['array', 'null'],
                'items' => ['type' => 'string', 'enum' => $this->choices($field)],
                'description' => $description,
            ],
            ContactFieldType::Email => [
                'type' => ['string', 'null'],
                'description' => $description.' Dirección de email.',
            ],
            ContactFieldType::Url => [
                'type' => ['string', 'null'],
                'description' => $description.' URL completa.',
            ],
            ContactFieldType::Phone => [
                'type' => ['string', 'null'],
                'maxLength' => 50,
                'description' => $description,
            ],
            // File queda excluido antes de llegar acá.
            ContactFieldType::File => [],
            ContactFieldType::Repeater => [
                'type' => ['array', 'null'],
                'items' => [
                    'type' => 'object',
                    'properties' => $this->repeaterProperties($field),
                    'required' => $this->repeaterRequired($field),
                    'additionalProperties' => false,
                ],
                'minItems' => (int) ($field->options['min_items'] ?? RepeaterFieldSchema::DEFAULT_MIN_ITEMS),
                'maxItems' => (int) ($field->options['max_items'] ?? RepeaterFieldSchema::DEFAULT_MAX_ITEMS),
                'description' => $description,
            ],
        };
    }

    /** @return array<string, mixed> */
    private function repeaterProperties(ContactField $field): array
    {
        $properties = [];
        foreach (($field->options['fields'] ?? []) as $nested) {
            if (! is_array($nested) || ($nested['is_active'] ?? true) === false || ! isset($nested['key'])) continue;
            $properties[$nested['key']] = $this->nestedProperty($nested);
        }

        return $properties;
    }

    /** @return list<string> */
    private function repeaterRequired(ContactField $field): array
    {
        return array_keys($this->repeaterProperties($field));
    }

    /** @param array<string, mixed> $field @return array<string, mixed> */
    private function nestedProperty(array $field): array
    {
        $type = match ($field['type'] ?? 'text') {
            'number' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
        if (($field['type'] ?? null) === 'select') {
            return [
                'anyOf' => [
                    ['type' => 'string', 'enum' => array_values($field['options']['choices'] ?? [])],
                    ['type' => 'null'],
                ],
                'description' => (string) ($field['label'] ?? $field['key']),
            ];
        }

        return [
            'type' => [$type, 'null'],
            'description' => (string) ($field['label'] ?? $field['key']),
        ];
    }

    /**
     * El label que le puso el tenant es la única pista de qué significa el
     * campo, así que va como description del schema.
     */
    private function describe(ContactField $field): string
    {
        return $field->label.'.';
    }

    /**
     * @return list<string>
     */
    private function choices(ContactField $field): array
    {
        $choices = $field->options['choices'] ?? [];

        return is_array($choices) ? array_values(array_filter($choices, 'is_string')) : [];
    }
}
