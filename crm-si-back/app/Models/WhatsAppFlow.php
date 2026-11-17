<?php

namespace App\Models;

use App\Enums\WhatsAppFlowStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Flows\FlowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppFlow extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_flows';

    public const DEFAULT_SEND_BODY = 'Tocá el botón para completar el formulario.';

    public const DEFAULT_SEND_CTA = 'Completar';

    protected $fillable = [
        'tenant_id',
        'whatsapp_config_id',
        'meta_flow_id',
        'name',
        'status',
        'categories',
        'flow_json',
        'json_version',
        'data_api_version',
        'endpoint_uri',
        'validation_errors',
        'field_mappings',
        'synced_at',
        'published_at',
        'builder_definition',
        'send_defaults',
        'opening',
        'completion_message',
        'published_version',
    ];

    protected $casts = [
        'status' => WhatsAppFlowStatus::class,
        'categories' => 'array',
        'validation_errors' => 'array',
        'field_mappings' => 'array',
        'builder_definition' => 'array',
        'send_defaults' => 'array',
        'opening' => 'array',
        'completion_message' => 'array',
        'published_version' => 'array',
        'synced_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function whatsappConfig(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConfig::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WhatsAppFlowSession::class);
    }

    /**
     * Textos del mensaje con el que se envía el formulario. Sin textos
     * guardados, el cuerpo es el primer título del constructor (si lo hay).
     * `variables` dice de dónde sale cada `{{nombre}}` del cuerpo, con la
     * forma de los parámetros de plantilla.
     *
     * @return array{header: ?string, image: array{source: 'media_asset', media_asset_id: int}|array{source: 'literal', value: string}|null, body: string, footer: ?string, cta: string, variables: list<array<string, mixed>>}
     */
    public function sendDefaults(): array
    {
        $stored = $this->send_defaults ?? [];

        $image = self::messageImage($stored['image'] ?? null);

        return [
            // Meta admite un solo encabezado: con imagen no va el de texto.
            'header' => $image === null && filled($stored['header'] ?? null) ? $stored['header'] : null,
            'image' => $image,
            'body' => filled($stored['body'] ?? null) ? $stored['body'] : ($this->firstHeading() ?? self::DEFAULT_SEND_BODY),
            'footer' => filled($stored['footer'] ?? null) ? $stored['footer'] : null,
            'cta' => filled($stored['cta'] ?? null) ? $stored['cta'] : self::DEFAULT_SEND_CTA,
            'variables' => array_values($stored['variables'] ?? []),
        ];
    }

    private function firstHeading(): ?string
    {
        $blocks = $this->builder_definition['screens'][0]['blocks'] ?? [];
        $heading = collect($blocks)->first(fn ($block) => ($block['type'] ?? null) === 'heading' && filled($block['text'] ?? null));

        return $heading['text'] ?? null;
    }

    /**
     * Plantillas de apertura (para la ventana de 24 h cerrada): la elegida como
     * predeterminada y las variables configuradas por id de plantilla. Las
     * resuelve `FlowOpeningTemplates`.
     *
     * @return array{default_template_id: ?int, templates: array<string, array{parameters: list<array<string, mixed>>}>}
     */
    public function openingConfig(): array
    {
        $stored = $this->opening ?? [];
        $templates = [];

        foreach (is_array($stored['templates'] ?? null) ? $stored['templates'] : [] as $id => $config) {
            $templates[(string) $id] = ['parameters' => array_values((array) ($config['parameters'] ?? []))];
        }

        return [
            'default_template_id' => isset($stored['default_template_id']) ? (int) $stored['default_template_id'] : null,
            'templates' => $templates,
        ];
    }

    /** @return list<array<string, mixed>> variables configuradas para esa plantilla de apertura */
    public function openingParameters(int $templateId): array
    {
        return $this->openingConfig()['templates'][(string) $templateId]['parameters'] ?? [];
    }

    /**
     * Cierre: lo que el CRM manda en el chat cuando llega la respuesta (local,
     * nunca va a Meta). Un texto (con botón de link opcional, que sólo existe
     * con texto y URL, e imagen opcional) o una plantilla con sus variables.
     * Encabezado de texto y pie sólo existen con botón: Meta los admite en el
     * mensaje interactivo, no en un texto ni en una imagen. Con imagen no hay
     * encabezado de texto, porque el mensaje lleva uno solo.
     *
     * @return array{type: 'text', body: string, header: ?string, footer: ?string, button_text: ?string, url: ?string, image: array{source: 'media_asset', media_asset_id: int}|array{source: 'literal', value: string}|null}|array{type: 'template', template_id: int, parameters: list<array<string, mixed>>}|null
     */
    public function completionMessage(): ?array
    {
        $stored = $this->completion_message ?? [];

        if (($stored['type'] ?? null) === 'template') {
            return isset($stored['template_id'])
                ? ['type' => 'template', 'template_id' => (int) $stored['template_id'], 'parameters' => array_values((array) ($stored['parameters'] ?? []))]
                : null;
        }

        if (($stored['type'] ?? null) !== 'text' || ! filled($stored['body'] ?? null)) {
            return null;
        }

        $hasButton = filled($stored['button_text'] ?? null) && filled($stored['url'] ?? null);
        $image = self::messageImage($stored['image'] ?? null);

        return [
            'type' => 'text',
            'body' => (string) $stored['body'],
            'header' => $hasButton && $image === null && filled($stored['header'] ?? null) ? (string) $stored['header'] : null,
            'footer' => $hasButton && filled($stored['footer'] ?? null) ? (string) $stored['footer'] : null,
            'button_text' => $hasButton ? (string) $stored['button_text'] : null,
            'url' => $hasButton ? (string) $stored['url'] : null,
            'image' => $image,
            'variables' => array_values((array) ($stored['variables'] ?? [])),
        ];
    }

    /**
     * Imagen de encabezado de la apertura o del cierre de texto, con la forma
     * del encabezado de una plantilla: un archivo del espacio o una URL pública.
     *
     * @return array{source: 'media_asset', media_asset_id: int}|array{source: 'literal', value: string}|null
     */
    public static function messageImage(mixed $image): ?array
    {
        $source = is_array($image) ? ($image['source'] ?? null) : null;

        return match (true) {
            $source === 'media_asset' && filled($image['media_asset_id'] ?? null) => ['source' => 'media_asset', 'media_asset_id' => (int) $image['media_asset_id']],
            $source === 'literal' && filled($image['value'] ?? null) => ['source' => 'literal', 'value' => (string) $image['value']],
            default => null,
        };
    }

    /** Versión que se está editando (la que se publica). */
    public function draftVersion(): FlowVersion
    {
        return new FlowVersion($this->flow_json, $this->builder_definition, $this->field_mappings ?? [], $this->data_api_version);
    }

    /**
     * Versión que corre el cliente. Meta sigue sirviendo la última versión
     * publicada hasta que se publique de nuevo, aunque el borrador cambie; sólo
     * un formulario nunca publicado (envíos de prueba en `mode=draft`) corre el
     * borrador. Los mapeos de un formulario por JSON no viajan en el Flow JSON
     * y se aplican siempre los vigentes.
     */
    public function liveVersion(): FlowVersion
    {
        if (! $this->hasPublishedVersion() || ! is_array($this->published_version)) {
            return $this->draftVersion();
        }

        $published = FlowVersion::fromSnapshot($this->published_version);

        return $published->definition !== null
            ? $published
            : new FlowVersion($published->flowJson, null, $this->field_mappings ?? [], $published->dataApiVersion);
    }

    /** Guarda el borrador actual como la versión publicada. No guarda el modelo. */
    public function rememberPublishedVersion(): void
    {
        $this->published_version = $this->draftVersion()->toSnapshot();
    }

    public function hasPublishedVersion(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Registra la primera vez que se vio el formulario publicado. No guarda:
     * lo llama quien después hace el save().
     */
    public function markPublishedIfNeeded(): void
    {
        $everPublished = in_array($this->status, [
            WhatsAppFlowStatus::Published,
            WhatsAppFlowStatus::Throttled,
            WhatsAppFlowStatus::Blocked,
            WhatsAppFlowStatus::Deprecated,
        ], true);

        if ($everPublished && $this->published_at === null) {
            $this->published_at = now();
        }
    }
}
