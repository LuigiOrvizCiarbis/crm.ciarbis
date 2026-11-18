<?php

namespace App\Http\Resources;

use App\Models\WhatsAppFlow;
use App\Models\WhatsAppTemplate;
use App\Services\Flows\FlowOpeningTemplates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/** @mixin WhatsAppFlow */
class WhatsAppFlowResource extends JsonResource
{
    /**
     * @param  Collection<int, WhatsAppTemplate>|null  $accountTemplates  plantillas de la cuenta ya cargadas (una lista las comparte)
     */
    public function __construct(WhatsAppFlow $resource, private readonly ?Collection $accountTemplates = null)
    {
        parent::__construct($resource);
    }

    /**
     * Formularios de una misma cuenta: las plantillas se cargan una sola vez.
     *
     * @param  Collection<int, WhatsAppFlow>  $flows
     * @return list<array<string, mixed>>
     */
    public static function forAccount(Collection $flows, Request $request): array
    {
        if ($flows->isEmpty()) {
            return [];
        }

        $templates = app(FlowOpeningTemplates::class)->accountTemplates($flows->first());

        return $flows->map(fn (WhatsAppFlow $flow) => (new self($flow, $templates))->resolve($request))->values()->all();
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $opening = app(FlowOpeningTemplates::class)->summary($this->resource, $this->accountTemplates);

        return [
            'id' => $this->id,
            'whatsapp_config_id' => $this->whatsapp_config_id,
            'meta_flow_id' => $this->meta_flow_id,
            'name' => $this->name,
            'status' => $this->status->value,
            'categories' => $this->categories ?? [],
            'json_version' => $this->json_version,
            'data_api_version' => $this->data_api_version,
            'uses_endpoint' => $this->draftVersion()->usesEndpoint(),
            'endpoint_uri' => $this->endpoint_uri,
            'validation_errors' => $this->validation_errors ?? [],
            'field_mappings' => $this->field_mappings ?? [],
            // Las de la versión que corre el cliente: el botón de una plantilla navega a una de ellas.
            'screens' => $this->liveVersion()->screenIds(),
            'has_published_version' => $this->hasPublishedVersion(),
            'has_builder_definition' => $this->builder_definition !== null,
            'send_defaults' => $this->sendDefaults(),
            // false: los textos son los sugeridos (primer título del constructor y «Completar»).
            'send_defaults_saved' => $this->send_defaults !== null,
            // Apertura con la ventana de 24 h cerrada: plantillas que abren el formulario.
            'opening' => $this->openingConfig(),
            'send_templates' => $opening['templates'],
            'effective_template_id' => $opening['effective_template_id'],
            'completion_message' => $this->completionMessage(),
            // Respuestas que el cierre puede citar ({{clave}} o variables de la
            // plantilla): las de la versión que corre el cliente.
            'response_fields' => $this->liveVersion()->responseFields(),
            'synced_at' => $this->synced_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
