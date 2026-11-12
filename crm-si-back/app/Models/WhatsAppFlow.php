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
    ];

    protected $casts = [
        'status' => WhatsAppFlowStatus::class,
        'categories' => 'array',
        'validation_errors' => 'array',
        'field_mappings' => 'array',
        'builder_definition' => 'array',
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
