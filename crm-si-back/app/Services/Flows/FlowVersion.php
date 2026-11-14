<?php

namespace App\Services\Flows;

/**
 * Una versión de un formulario: su Flow JSON y, si viene del constructor, la
 * definición y los mapeos compilados con ella. El endpoint, la respuesta y el
 * envío leen la versión que corre el cliente (`WhatsAppFlow::liveVersion`).
 */
final readonly class FlowVersion
{
    /**
     * @param  array<string, mixed>|null  $definition
     * @param  list<array{response_key: string, target: string}>  $fieldMappings
     */
    public function __construct(
        public ?string $flowJson,
        public ?array $definition,
        public array $fieldMappings,
        public ?string $dataApiVersion,
    ) {}

    /** @param  array<string, mixed>  $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        return new self(
            is_string($snapshot['flow_json'] ?? null) ? $snapshot['flow_json'] : null,
            is_array($snapshot['builder_definition'] ?? null) ? $snapshot['builder_definition'] : null,
            is_array($snapshot['field_mappings'] ?? null) ? $snapshot['field_mappings'] : [],
            is_string($snapshot['data_api_version'] ?? null) ? $snapshot['data_api_version'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'flow_json' => $this->flowJson,
            'builder_definition' => $this->definition,
            'field_mappings' => $this->fieldMappings,
            'data_api_version' => $this->dataApiVersion,
        ];
    }

    public function decodedJson(): ?array
    {
        if ($this->flowJson === null) {
            return null;
        }

        $decoded = json_decode($this->flowJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<string> */
    public function screenIds(): array
    {
        return array_values(array_filter(
            array_column($this->decodedJson()['screens'] ?? [], 'id'),
            'is_string',
        ));
    }

    public function entryScreen(): ?string
    {
        return $this->screenIds()[0] ?? null;
    }

    /**
     * Claves del objeto `data` declarado por la pantalla. Null significa
     * "desconocido" (no hay Flow JSON local): quien lo use no debe filtrar.
     *
     * @return list<string>|null
     */
    public function declaredDataKeys(string $screenId): ?array
    {
        $json = $this->decodedJson();

        if ($json === null) {
            return null;
        }

        foreach ($json['screens'] ?? [] as $screen) {
            if (($screen['id'] ?? null) === $screenId) {
                return array_map('strval', array_keys(is_array($screen['data'] ?? null) ? $screen['data'] : []));
            }
        }

        return [];
    }

    public function usesEndpoint(): bool
    {
        return $this->dataApiVersion !== null;
    }

    /** @return list<array<string, mixed>>|null bloques de la pantalla del constructor; null fuera del constructor */
    public function screenBlocks(int $index): ?array
    {
        return $this->definition['screens'][$index]['blocks'] ?? null;
    }

    /** @return array<string, string> clave de cada pregunta del constructor → su etiqueta */
    public function questionLabels(): array
    {
        $labels = [];

        foreach ($this->definition['screens'] ?? [] as $screen) {
            foreach ($screen['blocks'] ?? [] as $block) {
                if (is_string($block['key'] ?? null) && filled($block['label'] ?? null)) {
                    $labels[$block['key']] = (string) $block['label'];
                }
            }
        }

        return $labels;
    }
}
