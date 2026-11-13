<?php

namespace App\Services\Flows;

use App\Enums\WhatsAppFlowStatus;
use App\Models\Scopes\TenantScope;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

/**
 * Cliente de la API de WhatsApp Flows de Meta y espejo local en `whatsapp_flows`.
 */
class WhatsAppFlowService
{
    private const FIELDS = 'id,name,status,categories,validation_errors,json_version,data_api_version,endpoint_uri';

    /** Formulario viejo que Meta no deja editar: hay que duplicarlo. */
    private const ERROR_NOT_EDITABLE = 139001;

    public function __construct(
        private readonly WhatsAppFlowEncryptionService $encryption,
    ) {}

    public function sync(WhatsAppConfig $config, int $tenantId): int
    {
        $nextUrl = $this->graph("{$config->waba_id}/flows");
        $query = ['fields' => self::FIELDS, 'limit' => 100];
        $seen = [];

        do {
            $response = $this->client($config)->get($nextUrl, $query);
            $this->ensureSuccessful($response);

            foreach ($response->json('data', []) as $item) {
                $seen[] = (string) $item['id'];
                $flowJson = $this->downloadFlowJson($config, (string) $item['id']);
                $flow = $this->upsert($config, $tenantId, $item, $flowJson);

                // Fuera de borrador, el JSON de Meta es el que corre el cliente.
                if ($flowJson !== null && $flow->status !== WhatsAppFlowStatus::Draft) {
                    $flow->rememberPublishedVersion();
                    $flow->save();
                }
            }

            $nextUrl = $response->json('paging.next');
            $query = [];
        } while ($nextUrl);

        WhatsAppFlow::withoutGlobalScope(TenantScope::class)
            ->where('whatsapp_config_id', $config->id)
            ->whereNotIn('meta_flow_id', $seen)
            ->delete();

        return count($seen);
    }

    public function create(WhatsAppConfig $config, int $tenantId, string $name, array $categories, string $flowJson): WhatsAppFlow
    {
        $usesEndpoint = $this->declaresDataApi($flowJson);
        $payload = ['name' => $name, 'categories' => array_values($categories), 'flow_json' => $flowJson];

        if ($usesEndpoint) {
            $this->ensureEncryption($config);
            $payload['endpoint_uri'] = $config->flowsEndpointUrl();
        }

        $response = $this->client($config)->post($this->graph("{$config->waba_id}/flows"), $payload);
        $this->ensureSuccessful($response);

        $metaFlowId = (string) $response->json('id');

        if ($usesEndpoint) {
            $this->connectEndpoint($config, $metaFlowId);
        }

        return $this->refresh($config, $tenantId, $metaFlowId, $flowJson);
    }

    public function updateJson(WhatsAppFlow $flow, string $flowJson): WhatsAppFlow
    {
        $this->ensureEditable($flow);
        $config = $flow->whatsappConfig;

        $response = $this->client($config)
            ->timeout(30)
            ->attach('file', $flowJson, 'flow.json', ['Content-Type' => 'application/json'])
            ->post($this->graph("{$flow->meta_flow_id}/assets"), [
                'name' => 'flow.json',
                'asset_type' => 'FLOW_JSON',
            ]);
        $this->ensureEditableResponse($response);
        $this->ensureSuccessful($response);

        $flow->forceFill(['flow_json' => $flowJson])->save();

        if ($this->declaresDataApi($flowJson)) {
            $this->ensureEncryption($config);
            $this->attachEndpoint($flow);
        }

        return $this->refresh($config, $flow->tenant_id, $flow->meta_flow_id, $flowJson);
    }

    public function updateMetadata(WhatsAppFlow $flow, ?string $name, ?array $categories): WhatsAppFlow
    {
        $this->ensureEditable($flow);
        $payload = array_filter([
            'name' => $name,
            'categories' => $categories === null ? null : array_values($categories),
        ], fn ($value) => $value !== null);

        if ($payload !== []) {
            $response = $this->client($flow->whatsappConfig)->post($this->graph($flow->meta_flow_id), $payload);
            $this->ensureEditableResponse($response);
            $this->ensureSuccessful($response);
        }

        return $this->refresh($flow->whatsappConfig, $flow->tenant_id, $flow->meta_flow_id, $flow->flow_json);
    }

    public function attachEndpoint(WhatsAppFlow $flow): void
    {
        $this->connectEndpoint($flow->whatsappConfig, $flow->meta_flow_id);
    }

    public function publish(WhatsAppFlow $flow): WhatsAppFlow
    {
        $config = $flow->whatsappConfig;

        if ($flow->draftVersion()->usesEndpoint()) {
            if (! $this->encryption->isConfigured($config)) {
                throw new InvalidArgumentException('Configurá el cifrado del número antes de publicar un formulario con endpoint.');
            }

            if ($flow->endpoint_uri !== $config->flowsEndpointUrl()) {
                $this->attachEndpoint($flow);
            }
        }

        $response = $this->client($config)->post($this->graph("{$flow->meta_flow_id}/publish"));
        $this->ensureSuccessful($response);

        $published = $this->refresh($config, $flow->tenant_id, $flow->meta_flow_id, $flow->flow_json);
        $published->rememberPublishedVersion();
        $published->save();

        return $published;
    }

    public function deprecate(WhatsAppFlow $flow): WhatsAppFlow
    {
        $response = $this->client($flow->whatsappConfig)->post($this->graph("{$flow->meta_flow_id}/deprecate"));
        $this->ensureSuccessful($response);

        return $this->refresh($flow->whatsappConfig, $flow->tenant_id, $flow->meta_flow_id, $flow->flow_json);
    }

    public function delete(WhatsAppFlow $flow): void
    {
        if ($flow->status !== WhatsAppFlowStatus::Draft || $flow->hasPublishedVersion()) {
            throw new InvalidArgumentException('Sólo se pueden eliminar borradores que nunca se publicaron. Deprecalo en su lugar.');
        }

        $response = $this->client($flow->whatsappConfig)->delete($this->graph($flow->meta_flow_id));
        $this->ensureSuccessful($response);

        $flow->delete();
    }

    public function clone(WhatsAppFlow $flow, string $name): WhatsAppFlow
    {
        $config = $flow->whatsappConfig;
        $response = $this->client($config)->post($this->graph("{$config->waba_id}/flows"), [
            'name' => $name,
            'categories' => $flow->categories,
            'clone_flow_id' => $flow->meta_flow_id,
        ]);
        $this->ensureSuccessful($response);

        $copy = $this->refresh($config, $flow->tenant_id, (string) $response->json('id'), null);
        $copy->forceFill([
            'builder_definition' => $flow->builder_definition,
            'field_mappings' => $flow->field_mappings,
            'send_defaults' => $flow->send_defaults,
        ])->save();

        return $copy;
    }

    /** @return array{preview_url: string, expires_at: string} */
    public function previewUrl(WhatsAppFlow $flow): array
    {
        $response = $this->client($flow->whatsappConfig)
            ->get($this->graph($flow->meta_flow_id), ['fields' => 'preview.invalidate(false)']);
        $this->ensureSuccessful($response);

        return [
            'preview_url' => (string) $response->json('preview.preview_url'),
            'expires_at' => (string) $response->json('preview.expires_at'),
        ];
    }

    /**
     * Webhook `flows` de la WABA: cambios de estado y alertas de Meta.
     */
    public function handleWebhook(array $value): void
    {
        $event = $value['event'] ?? null;
        $status = WhatsAppFlowStatus::tryFrom((string) ($value['new_status'] ?? ''));

        if ($event !== 'FLOW_STATUS_CHANGE' || $status === null) {
            Log::warning('WhatsApp Flow alerta', $value);

            return;
        }

        $flows = WhatsAppFlow::withoutGlobalScopes()
            ->where('meta_flow_id', (string) ($value['flow_id'] ?? ''))
            ->get();

        if ($flows->isEmpty()) {
            Log::info('WhatsApp Flow desconocido en webhook de estado', ['flow_id' => $value['flow_id'] ?? null]);

            return;
        }

        foreach ($flows as $flow) {
            $flow->status = $status;
            $flow->synced_at = now();
            $flow->markPublishedIfNeeded();
            $flow->save();
        }
    }

    private function refresh(WhatsAppConfig $config, int $tenantId, string $metaFlowId, ?string $flowJson): WhatsAppFlow
    {
        $response = $this->client($config)->get($this->graph($metaFlowId), ['fields' => self::FIELDS]);
        $this->ensureSuccessful($response);

        return $this->upsert($config, $tenantId, $response->json(), $flowJson ?? $this->downloadFlowJson($config, $metaFlowId));
    }

    private function upsert(WhatsAppConfig $config, int $tenantId, array $item, ?string $flowJson): WhatsAppFlow
    {
        $flow = WhatsAppFlow::withoutGlobalScope(TenantScope::class)->firstOrNew([
            'whatsapp_config_id' => $config->id,
            'meta_flow_id' => (string) $item['id'],
        ]);

        $flow->fill([
            'tenant_id' => $tenantId,
            'name' => (string) ($item['name'] ?? $flow->name ?? ''),
            'status' => WhatsAppFlowStatus::tryFrom((string) ($item['status'] ?? '')) ?? WhatsAppFlowStatus::Draft,
            'categories' => $item['categories'] ?? $flow->categories ?? [],
            'json_version' => $item['json_version'] ?? null,
            'data_api_version' => $item['data_api_version'] ?? null,
            'endpoint_uri' => $item['endpoint_uri'] ?? null,
            'validation_errors' => $item['validation_errors'] ?? [],
            'synced_at' => now(),
        ]);

        if ($flowJson !== null) {
            $flow->flow_json = $flowJson;
        }

        $flow->markPublishedIfNeeded();
        $flow->save();

        return $flow;
    }

    /**
     * Descarga el Flow JSON vigente. Null si no se pudo: quien llama conserva
     * el JSON anterior.
     */
    private function downloadFlowJson(WhatsAppConfig $config, string $metaFlowId): ?string
    {
        try {
            $assets = $this->client($config)->get($this->graph("{$metaFlowId}/assets"));

            if ($assets->failed()) {
                throw new RuntimeException($assets->body());
            }

            $asset = collect($assets->json('data', []))->firstWhere('asset_type', 'FLOW_JSON');

            if (! $asset || empty($asset['download_url'])) {
                return null;
            }

            $download = Http::timeout(15)->get($asset['download_url']);

            if ($download->failed()) {
                throw new RuntimeException('HTTP '.$download->status());
            }

            return $download->body();
        } catch (ConnectionException|RuntimeException $e) {
            Log::warning('WhatsApp Flow: no se pudo descargar el Flow JSON', [
                'flow_id' => $metaFlowId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Conecta el endpoint de datos y la app de Meta al formulario. Si Meta
     * rechaza `application_id` (formularios creados en WhatsApp Manager), se
     * reintenta una vez sólo con el endpoint.
     */
    private function connectEndpoint(WhatsAppConfig $config, string $metaFlowId): void
    {
        $payload = ['endpoint_uri' => $config->flowsEndpointUrl()];
        $appId = config('services.facebook.app_id');

        if ($appId) {
            $response = $this->client($config)->post($this->graph($metaFlowId), $payload + ['application_id' => $appId]);

            if ($response->successful()) {
                return;
            }

            Log::warning('WhatsApp Flow: Meta rechazó application_id, se reintenta sin él', [
                'flow_id' => $metaFlowId,
                'body' => $response->body(),
            ]);
        }

        $this->ensureSuccessful($this->client($config)->post($this->graph($metaFlowId), $payload));
    }

    private function ensureEncryption(WhatsAppConfig $config): void
    {
        if (! $this->encryption->isConfigured($config)) {
            $this->encryption->setup($config);
        }
    }

    private function ensureEditable(WhatsAppFlow $flow): void
    {
        if (! $flow->status->isEditable()) {
            throw new InvalidArgumentException('Este formulario no se puede editar en su estado actual. Duplicalo para crear una nueva versión.');
        }
    }

    private function ensureEditableResponse(Response $response): void
    {
        $codes = [(int) $response->json('error.code'), (int) $response->json('error.error_subcode')];

        if ($response->failed() && in_array(self::ERROR_NOT_EDITABLE, $codes, true)) {
            throw new InvalidArgumentException('Este formulario no admite edición. Duplicalo para crear una nueva versión.');
        }
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.error_user_msg') ?: $response->json('error.message', $response->body());

        throw new RuntimeException('Meta rechazó la operación: '.$message);
    }

    private function declaresDataApi(string $flowJson): bool
    {
        $decoded = json_decode($flowJson, true);

        return is_array($decoded) && isset($decoded['data_api_version']);
    }

    private function client(WhatsAppConfig $config): PendingRequest
    {
        $token = $config->getDecryptedToken();

        if (! $token) {
            throw new RuntimeException('No se pudo desencriptar el token de WhatsApp');
        }

        return Http::withToken($token)->timeout(15);
    }

    private function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.$this->graphVersion().'/'.$path;
    }

    private function graphVersion(): string
    {
        return (string) config('services.facebook.graph_version', 'v26.0');
    }
}
