<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BillingConfigRequest;
use App\Models\BillingConfig;
use App\Models\Scopes\TenantScope;
use App\Models\WhatsAppTemplate;
use App\Support\BillingTemplateDrafts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingConfigController extends Controller
{
    /**
     * Borradores de las plantillas de cobranza, más el estado de las que ya
     * se pidieron a Meta.
     *
     * Devuelve textos sugeridos, no los crea: el front los muestra editables y
     * al confirmar llama al endpoint que ya existe
     * (POST /channels/{channel}/templates), uno por plantilla. Así el texto
     * editado por el usuario pasa por la misma validación que cualquier otra
     * plantilla y no se duplica el manejo de errores de Meta.
     */
    public function templateDrafts(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('billing.manage')) {
            abort(403);
        }

        $drafts = BillingTemplateDrafts::all($user->tenant);

        // Estado actual de cada plantilla: el front distingue "todavía no se
        // pidió" de "Meta la está revisando" y de "lista para activar".
        $existing = WhatsAppTemplate::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $user->tenant_id)
            ->whereIn('name', array_column($drafts, 'name'))
            ->get()
            ->keyBy('name');

        $data = array_map(function (array $draft) use ($existing): array {
            $template = $existing->get($draft['name']);

            return [
                ...$draft,
                'template' => $template === null ? null : [
                    'id' => $template->id,
                    'status' => $template->status->value,
                    'status_label' => $template->status->label(),
                    'rejected_reason' => $template->rejected_reason,
                ],
            ];
        }, $drafts);

        return response()->json(['data' => $data]);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('billing.view') && ! $user?->can('billing.manage')) {
            abort(403);
        }

        $config = BillingConfig::where('tenant_id', $user->tenant_id)->first();

        return response()->json(['data' => $this->transform($config)]);
    }

    public function update(BillingConfigRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $config = BillingConfig::firstOrNew(['tenant_id' => $user->tenant_id]);
        $config->tenant_id = $user->tenant_id;
        $config->fill($validated);
        $config->save();

        return response()->json(['data' => $this->transform($config->fresh())]);
    }

    /** @return array<string, mixed> */
    private function transform(?BillingConfig $config): array
    {
        if (! $config) {
            return [
                'enabled' => false,
                'due_date_field_key' => null,
                'status_field_key' => null,
                'overdue_cycles_field_key' => null,
                'externally_managed_field_key' => null,
                'cycle_unit' => 'months',
                'cycle_length' => 1,
                'timezone' => null,
                'grace_days' => 3,
                'last_rolled_at' => null,
            ];
        }

        return [
            'enabled' => $config->enabled,
            'due_date_field_key' => $config->due_date_field_key,
            'status_field_key' => $config->status_field_key,
            'overdue_cycles_field_key' => $config->overdue_cycles_field_key,
            'externally_managed_field_key' => $config->externally_managed_field_key,
            'cycle_unit' => $config->cycle_unit,
            'cycle_length' => $config->cycle_length,
            'timezone' => $config->timezone,
            'grace_days' => $config->grace_days,
            'last_rolled_at' => $config->last_rolled_at?->toIso8601String(),
        ];
    }
}
