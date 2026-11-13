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
        'published_version',
    ];

    protected $casts = [
        'status' => WhatsAppFlowStatus::class,
        'categories' => 'array',
        'validation_errors' => 'array',
        'field_mappings' => 'array',
        'builder_definition' => 'array',
        'send_defaults' => 'array',
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
     *
     * @return array{header: ?string, body: string, footer: ?string, cta: string}
     */
    public function sendDefaults(): array
    {
        $stored = $this->send_defaults ?? [];

        return [
            'header' => filled($stored['header'] ?? null) ? $stored['header'] : null,
            'body' => filled($stored['body'] ?? null) ? $stored['body'] : ($this->firstHeading() ?? self::DEFAULT_SEND_BODY),
            'footer' => filled($stored['footer'] ?? null) ? $stored['footer'] : null,
            'cta' => filled($stored['cta'] ?? null) ? $stored['cta'] : self::DEFAULT_SEND_CTA,
        ];
    }

    private function firstHeading(): ?string
    {
        $blocks = $this->builder_definition['screens'][0]['blocks'] ?? [];
        $heading = collect($blocks)->first(fn ($block) => ($block['type'] ?? null) === 'heading' && filled($block['text'] ?? null));

        return $heading['text'] ?? null;
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
