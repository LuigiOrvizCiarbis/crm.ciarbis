<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        DB::transaction(function () use ($pipeline, $validated): void {
            if (($validated['is_default'] ?? false) === true) {
                Pipeline::query()->whereKeyNot($pipeline->id)->update(['is_default' => false]);
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

        $pipeline->delete();

        return response()->json(['message' => 'Pipeline eliminado']);
    }
}
