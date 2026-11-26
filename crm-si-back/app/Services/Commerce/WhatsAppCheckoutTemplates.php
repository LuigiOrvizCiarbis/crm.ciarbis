<?php

namespace App\Services\Commerce;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Models\Conversation;
use App\Models\Scopes\TenantScope;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppTemplateService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plantillas de la compra por WhatsApp para avisar con la ventana de 24 h
 * cerrada (con la ventana abierta se manda texto, que no tiene costo). Se
 * crean al instalar la compra en cada número y se usan solas cuando Meta las
 * aprueba; una plantilla elegida a mano en la configuración tiene prioridad.
 */
class WhatsAppCheckoutTemplates
{
    public const PAID = 'pedido_pagado';

    public const SHIPPED = 'pedido_despachado';

    public const LANGUAGE = 'es_AR';

    /** Texto y ejemplos de cada plantilla; las variables son las que manda el ciclo de vida. */
    private const DEFINITIONS = [
        self::PAID => [
            'text' => '¡Recibimos tu pago! Tu pedido #{{pedido}} por {{total}} está confirmado. Te avisamos por acá cuando lo despachemos.',
            'examples' => ['pedido' => '1001', 'total' => '$34.696'],
        ],
        self::SHIPPED => [
            'text' => 'Tu pedido #{{pedido}} ya está en camino 🚚 Número de seguimiento: {{seguimiento}}',
            'examples' => ['pedido' => '1001', 'seguimiento' => 'CP123456789AR'],
        ],
    ];

    public function __construct(private readonly WhatsAppTemplateService $templates) {}

    /**
     * Crea en la cuenta de WhatsApp del número las plantillas que falten.
     * Nunca corta la instalación: un rechazo de Meta queda en el resultado.
     *
     * @return array{statuses: array<string, ?string>, error: ?string}
     */
    public function ensure(WhatsAppConfig $config, int $tenantId): array
    {
        $statuses = [];
        $error = null;

        foreach (self::DEFINITIONS as $name => $definition) {
            try {
                $template = $this->find($config->id, $name)
                    ?? $this->templates->createTemplate($config, $tenantId, [
                        'name' => $name,
                        'language' => self::LANGUAGE,
                        'category' => TemplateCategory::Utility->value,
                        'components' => [[
                            'type' => 'BODY',
                            'text' => $definition['text'],
                            'example' => ['body_text_named_params' => array_map(
                                fn (string $param, string $example) => ['param_name' => $param, 'example' => $example],
                                array_keys($definition['examples']),
                                $definition['examples'],
                            )],
                        ]],
                    ]);
                $statuses[$name] = $template->status?->value;
            } catch (Throwable $e) {
                Log::warning('Compra por WhatsApp: no se pudo crear la plantilla', [
                    'whatsapp_config_id' => $config->id,
                    'template' => $name,
                    'error' => $e->getMessage(),
                ]);
                $statuses[$name] = null;
                $error ??= $e->getMessage();
            }
        }

        return ['statuses' => $statuses, 'error' => $error];
    }

    /** Plantilla aprobada del número de la conversación, o null si todavía no la aprobaron. */
    public function approvedFor(Conversation $conversation, string $name): ?WhatsAppTemplate
    {
        $configId = $conversation->channel?->whatsapp_config_id;
        $template = $configId === null ? null : $this->find($configId, $name);

        return $template?->status === TemplateStatus::Approved ? $template : null;
    }

    private function find(int $whatsappConfigId, string $name): ?WhatsAppTemplate
    {
        return WhatsAppTemplate::withoutGlobalScope(TenantScope::class)
            ->where('whatsapp_config_id', $whatsappConfigId)
            ->where('name', $name)
            ->where('language', self::LANGUAGE)
            ->first();
    }
}
