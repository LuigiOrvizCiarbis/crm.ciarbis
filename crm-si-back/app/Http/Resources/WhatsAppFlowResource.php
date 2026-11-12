<?php

namespace App\Http\Resources;

use App\Models\WhatsAppFlow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WhatsAppFlow */
class WhatsAppFlowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'whatsapp_config_id' => $this->whatsapp_config_id,
            'meta_flow_id' => $this->meta_flow_id,
            'name' => $this->name,
            'status' => $this->status->value,
            'categories' => $this->categories ?? [],
            'json_version' => $this->json_version,
            'data_api_version' => $this->data_api_version,
            'uses_endpoint' => $this->usesEndpoint(),
            'endpoint_uri' => $this->endpoint_uri,
            'validation_errors' => $this->validation_errors ?? [],
            'field_mappings' => $this->field_mappings ?? [],
            'screens' => $this->screenIds(),
            'has_published_version' => $this->hasPublishedVersion(),
            'has_builder_definition' => $this->builder_definition !== null,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
