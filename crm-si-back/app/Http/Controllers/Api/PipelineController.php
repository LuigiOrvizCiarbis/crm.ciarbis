<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\Pipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PipelineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Pipeline::class);

        return response()->json(Pipeline::withCount('opportunities')->orderByDesc('is_default')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Pipeline::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('pipelines')->where('tenant_id', $request->user()->tenant_id)],
            'is_default' => ['sometimes', 'boolean'],
            // Etapas iniciales (copia de otro pipeline o plantilla), en orden.
            'stages' => ['sometimes', 'array', 'max:30'],
            'stages.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'stages.*.color' => ['sometimes', 'string', 'regex:/^#([0-9A-Fa-f]{6})$/'],
        ], [
            'name.unique' => 'Ya existe un pipeline con ese nombre.',
            'stages.*.name.distinct' => 'Hay etapas con el mismo nombre.',
        ]);

        if (($validated['is_default'] ?? false) === true && empty($validated['stages'])) {
            return response()->json(['message' => 'Agregale al menos una etapa antes de hacerlo predeterminado: los chats nuevos entran en su primera etapa.'], 422);
        }

        if (($validated['is_default'] ?? false) === true && ($blocked = $this->blockDefaultSwitch())) {
            return $blocked;
        }

        $pipeline = DB::transaction(function () use ($validated): Pipeline {
            $isDefault = $validated['is_default'] ?? ! Pipeline::exists();
            if ($isDefault) {
                Pipeline::query()->update(['is_default' => false]);
            }

            $pipeline = Pipeline::create([
                'name' => $validated['name'],
                'is_default' => $isDefault,
            ]);

            foreach (array_values($validated['stages'] ?? []) as $index => $stage) {
                $pipeline->stages()->create([
                    'tenant_id' => $pipeline->tenant_id,
                    'name' => $stage['name'],
                    'color' => $stage['color'] ?? '#3B82F6',
                    'sort_order' => $index + 1,
                    'is_default' => $index === 0,
                ]);
            }

            return $pipeline;
        });

        return response()->json($pipeline->loadCount(['opportunities', 'stages']), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $pipeline = Pipeline::findOrFail($id);
        $this->authorize('update', $pipeline);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('pipelines')->where('tenant_id', $request->user()->tenant_id)->ignore($pipeline->id)],
            'is_default' => ['sometimes', 'boolean'],
        ], [
            'name.unique' => 'Ya existe un pipeline con ese nombre.',
        ]);

        if ($pipeline->is_default && ($validated['is_default'] ?? true) === false) {
            return response()->json(['message' => 'Elegí otro pipeline como predeterminado en lugar de desmarcar este.'], 422);
        }

        $promoting = ! $pipeline->is_default && ($validated['is_default'] ?? false) === true;

        if ($promoting && $pipeline->stages()->doesntExist()) {
            return response()->json(['message' => 'Agregale al menos una etapa antes de hacerlo predeterminado: los chats nuevos entran en su primera etapa.'], 422);
        }

        if ($promoting && ($blocked = $this->blockDefaultSwitch())) {
            return $blocked;
        }

        DB::transaction(function () use ($pipeline, $validated): void {
            if (($validated['is_default'] ?? false) === true) {
                Pipeline::query()->whereKeyNot($pipeline->id)->update(['is_default' => false]);
                if (! $pipeline->is_default) {
                    $this->reconcileChatStages($pipeline);
                }
            }
            $pipeline->update($validated);
        });

        return response()->json($pipeline->fresh()->loadCount('opportunities'));
    }

    public function destroy(string $id): JsonResponse
    {
        $pipeline = Pipeline::findOrFail($id);
        $this->authorize('delete', $pipeline);

        if ($pipeline->opportunities()->exists()) {
            return response()->json(['message' => 'Mové las oportunidades a otro pipeline antes de eliminarlo.'], 422);
        }

        if ($pipeline->is_default) {
            return response()->json(['message' => 'Elegí otro pipeline predeterminado antes de eliminar este.'], 422);
        }

        // Borrar el pipeline borra sus etapas, y los chats que estaban en
        // ellas quedarían sin etapa (nullOnDelete).
        $chats = $this->conversationsInStagesOf($pipeline);
        if ($chats > 0) {
            return response()->json(['message' => "Hay {$chats} chats en etapas de este pipeline. Cambiales la etapa antes de eliminarlo."], 422);
        }

        $pipeline->delete();

        return response()->json(['message' => 'Pipeline eliminado']);
    }

    private function conversationsInStagesOf(Pipeline $pipeline): int
    {
        return Conversation::whereIn('pipeline_stage_id', $pipeline->stages()->select('id'))->count();
    }

    /**
     * La etapa del chat siempre es del pipeline predeterminado: cambiarlo con
     * chats ya ubicados los dejaría apuntando a etapas de otro pipeline (fuera
     * del selector, del dashboard y de la sincronización con oportunidades).
     */
    private function blockDefaultSwitch(): ?JsonResponse
    {
        $current = Pipeline::where('is_default', true)->first();
        $chats = $current ? $this->conversationsInStagesOf($current) : 0;

        if ($chats === 0) {
            return null;
        }

        return response()->json([
            'message' => "No se puede cambiar el pipeline predeterminado: {$chats} chats tienen su etapa en «{$current->name}», y la etapa de un chat siempre pertenece al predeterminado.",
        ], 422);
    }

    /**
     * Al pasar a predeterminado, los chats sin etapa con oportunidades en este
     * pipeline toman la etapa de la más reciente (misma regla que el hook de
     * Opportunity). Update masivo a propósito: no dispara stage_changed, es
     * una reconciliación de datos y no debe lanzar automatizaciones en masa.
     */
    private function reconcileChatStages(Pipeline $pipeline): void
    {
        $latestByConversation = Opportunity::query()
            ->where('pipeline_id', $pipeline->id)
            ->whereNotNull('pipeline_stage_id')
            ->whereIn('conversation_id', Conversation::whereNull('pipeline_stage_id')->select('id'))
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get(['conversation_id', 'pipeline_stage_id'])
            ->keyBy('conversation_id');

        foreach ($latestByConversation->groupBy('pipeline_stage_id') as $stageId => $opportunities) {
            Conversation::whereIn('id', $opportunities->pluck('conversation_id'))
                ->whereNull('pipeline_stage_id')
                ->update(['pipeline_stage_id' => $stageId]);
        }
    }
}
