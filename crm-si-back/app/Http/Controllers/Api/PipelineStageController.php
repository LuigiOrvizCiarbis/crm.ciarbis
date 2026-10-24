<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class PipelineStageController extends Controller
{
    public function index(Request $request): Collection
    {
        $this->authorize('viewAny', PipelineStage::class);

        $query = PipelineStage::query()->orderBy('sort_order', 'asc');
        if ($request->filled('pipeline_id')) {
            $query->where('pipeline_id', $request->integer('pipeline_id'));
        } else {
            $query->whereHas('pipeline', fn ($builder) => $builder->where('is_default', true));
        }

        return $query->get();
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PipelineStage::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'pipeline_id' => ['sometimes', 'integer', Rule::exists('pipelines', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'color' => ['sometimes', 'string', 'regex:/^#([0-9A-Fa-f]{6})$/'],
        ]);

        // Sin pipeline_id explícito la etapa va al pipeline predeterminado.
        $pipelineId = $validated['pipeline_id'] ?? Pipeline::defaultFor($request->user()->tenant_id)->id;
        $maxOrder = PipelineStage::where('pipeline_id', $pipelineId)->max('sort_order') ?? 0;

        $stage = PipelineStage::create([
            'name' => $validated['name'],
            'pipeline_id' => $pipelineId,
            'color' => $validated['color'] ?? '#3B82F6',
            'sort_order' => $maxOrder + 1,
        ]);

        return response()->json($stage, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $stage = PipelineStage::findOrFail($id);

        $this->authorize('update', $stage);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'color' => ['sometimes', 'string', 'regex:/^#([0-9A-Fa-f]{6})$/'],
            'sort_order' => 'sometimes|integer',
        ]);

        $stage->update($validated);

        return response()->json($stage);
    }

    public function destroy(string $id): Response
    {
        $stage = PipelineStage::findOrFail($id);

        $this->authorize('delete', $stage);

        $stage->delete();

        return response()->noContent();
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorize('reorder', PipelineStage::class);

        $request->validate([
            'pipeline_id' => ['sometimes', 'integer', Rule::exists('pipelines', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'stages' => 'required|array',
            'stages.*.id' => ['required', Rule::exists('pipeline_stages', 'id')->where(fn ($query) => $query
                ->where('tenant_id', $request->user()->tenant_id)
                ->when($request->filled('pipeline_id'), fn ($query) => $query->where('pipeline_id', $request->integer('pipeline_id'))))],
            'stages.*.sort_order' => 'required|integer',
        ]);

        foreach ($request->stages as $item) {
            PipelineStage::where('id', $item['id'])->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['message' => 'Orden actualizado']);
    }
}
