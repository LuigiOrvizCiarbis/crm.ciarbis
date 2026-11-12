<?php

namespace App\Models;

use App\Enums\WhatsAppFlowStatus;
use App\Models\Concerns\BelongsToTenant;
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
    ];

    protected $casts = [
        'status' => WhatsAppFlowStatus::class,
        'categories' => 'array',
        'validation_errors' => 'array',
        'field_mappings' => 'array',
        'builder_definition' => 'array',
        'send_defaults' => 'array',
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

    public function decodedJson(): ?array
    {
        if ($this->flow_json === null) {
            return null;
        }

        $decoded = json_decode($this->flow_json, true);

        return is_array($decoded) ? $decoded : null;
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
        return $this->data_api_version !== null;
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
