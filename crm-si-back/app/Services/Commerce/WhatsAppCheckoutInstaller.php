<?php

namespace App\Services\Commerce;

use App\Enums\ChannelType;
use App\Enums\WhatsAppFlowStatus;
use App\Models\Conversation;
use App\Models\Scopes\TenantScope;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Services\Flows\WhatsAppFlowService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Instala en cada número de WhatsApp del tenant el formulario de compra
 * (`resources/flows/whatsapp-checkout.json`) y habilita catálogo y carrito.
 * Es idempotente: con el mismo JSON publicado no hace nada nuevo.
 */
class WhatsAppCheckoutInstaller
{
    public const FLOW_NAME = 'Compra por WhatsApp';

    public function __construct(
        private readonly WhatsAppFlowService $flows,
        private readonly WhatsAppCommerceSettings $commerceSettings,
        private readonly WhatsAppCheckoutTemplates $templates,
    ) {}

    /**
     * Un error del formulario corta la instalación (lo muestra el controller);
     * uno de la configuración del catálogo o de las plantillas sólo se informa:
     * el catálogo se puede activar a mano y sin plantilla sólo se pierde el
     * aviso con la ventana cerrada.
     *
     * @return list<array{whatsapp_config_id: int, flow_id: int, status: string, commerce_error: ?string, templates: array<string, ?string>, template_error: ?string}>
     */
    public function install(int $tenantId): array
    {
        $json = $this->flowJson();
        $results = [];

        foreach ($this->configs($tenantId) as $config) {
            $commerceError = null;

            try {
                $this->commerceSettings->enable($config);
            } catch (RuntimeException $e) {
                Log::warning('Compra por WhatsApp: no se pudo habilitar el carrito', [
                    'whatsapp_config_id' => $config->id,
                    'error' => $e->getMessage(),
                ]);
                $commerceError = $e->getMessage();
            }

            $flow = $this->installFlow($config, $tenantId, $json);
            $templates = $this->templates->ensure($config, $tenantId);

            $results[] = [
                'whatsapp_config_id' => $config->id,
                'flow_id' => $flow->id,
                'status' => $flow->status->value,
                'commerce_error' => $commerceError,
                'templates' => $templates['statuses'],
                'template_error' => $templates['error'],
            ];
        }

        return $results;
    }

    public function flowFor(Conversation $conversation): ?WhatsAppFlow
    {
        $configId = $conversation->channel?->whatsapp_config_id;

        if ($configId === null) {
            return null;
        }

        return WhatsAppFlow::withoutGlobalScope(TenantScope::class)
            ->where('whatsapp_config_id', $configId)
            ->where('system_key', WhatsAppFlow::SYSTEM_CHECKOUT)
            ->where('status', WhatsAppFlowStatus::Published)
            ->first();
    }

    public function hasPublishedFlow(int $tenantId): bool
    {
        return WhatsAppFlow::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('system_key', WhatsAppFlow::SYSTEM_CHECKOUT)
            ->where('status', WhatsAppFlowStatus::Published)
            ->exists();
    }

    private function installFlow(WhatsAppConfig $config, int $tenantId, string $json): WhatsAppFlow
    {
        $flow = WhatsAppFlow::withoutGlobalScope(TenantScope::class)
            ->where('whatsapp_config_id', $config->id)
            ->where('system_key', WhatsAppFlow::SYSTEM_CHECKOUT)
            ->first();

        if ($flow === null) {
            $flow = $this->flows->create($config, $tenantId, self::FLOW_NAME, ['SHOPPING'], $json);
            $flow->forceFill(['system_key' => WhatsAppFlow::SYSTEM_CHECKOUT])->save();
        } elseif ($flow->status === WhatsAppFlowStatus::Published) {
            if (! $this->sameJson($flow->published_version['flow_json'] ?? null, $json)) {
                $flow = $this->replacePublished($flow, $json);
            }
        } elseif (! $this->sameJson($flow->flow_json, $json)) {
            $flow = $this->flows->updateJson($flow, $json);
        }

        if ($flow->status !== WhatsAppFlowStatus::Published) {
            $flow = $this->flows->publish($flow);
        }

        return $flow;
    }

    /**
     * Un formulario publicado no se edita: se clona, se publica la copia y se
     * depreca el viejo. La copia recién toma la marca de sistema ya publicada:
     * si Meta rechaza el JSON nuevo, el formulario viejo sigue atendiendo. Meta
     * no admite dos formularios con el mismo nombre en la cuenta (tampoco
     * deprecados), así que cada copia lleva la fecha de publicación.
     */
    private function replacePublished(WhatsAppFlow $old, string $json): WhatsAppFlow
    {
        $name = self::FLOW_NAME.' ('.now()->format('Y-m-d H:i:s').')';
        $copy = $this->flows->publish($this->flows->updateJson($this->flows->clone($old, $name), $json));

        DB::transaction(function () use ($old, $copy) {
            $old->forceFill(['system_key' => null])->save();
            $copy->forceFill(['system_key' => WhatsAppFlow::SYSTEM_CHECKOUT])->save();
        });
        $this->flows->deprecate($old);

        return $copy;
    }

    /** @return iterable<WhatsAppConfig> */
    private function configs(int $tenantId): iterable
    {
        return WhatsAppConfig::query()
            ->whereHas('channels', fn ($query) => $query->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('type', ChannelType::WHATSAPP))
            ->orderBy('id')
            ->get();
    }

    private function sameJson(?string $current, string $json): bool
    {
        return $current !== null && json_decode($current, true) === json_decode($json, true);
    }

    private function flowJson(): string
    {
        $json = file_get_contents(resource_path('flows/whatsapp-checkout.json'));

        if ($json === false) {
            throw new RuntimeException('No se encontró el formulario de compra.');
        }

        return $json;
    }
}
