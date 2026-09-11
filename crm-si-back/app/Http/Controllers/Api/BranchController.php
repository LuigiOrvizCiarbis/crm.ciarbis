<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\JsonResponse;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Branch::class);

        $query = Branch::query()->orderBy('name');

        if ($request->boolean('only_active')) {
            $query->where('is_active', true);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Branch::class);

        $validated = $request->validate($this->rules($request));

        $branch = Branch::create([
            ...$validated,
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json(['data' => $branch], 201);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $this->authorize('view', $branch);

        return response()->json(['data' => $branch->loadMissing('manager')]);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        $this->authorize('update', $branch);

        $validated = $request->validate($this->rules($request, $branch));

        $branch->update($validated);

        return response()->json(['data' => $branch->refresh()]);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        $this->authorize('delete', $branch);

        if ($branch->users()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la sucursal: tiene usuarios asignados. Reasigne los usuarios antes de eliminar.',
            ], 422);
        }

        $branch->delete();

        return response()->json(['message' => 'Sucursal eliminada']);
    }

    public function stats(Request $request, Branch $branch): JsonResponse
    {
        $this->authorize('viewStats', $branch);

        $rango = (int) $request->query('rango', 30);
        if (! in_array($rango, [7, 30, 90], true)) {
            $rango = 30;
        }

        $cacheKey = "branches.stats.{$branch->tenant_id}.{$branch->id}.{$rango}";

        $stats = Cache::remember($cacheKey, 60, fn () => $this->buildStatsFor($branch->id, $branch->tenant_id, $rango));

        return response()->json(['data' => $stats]);
    }

    private function rules(Request $request, ?Branch $branch = null): array
    {
        $tenantId = $request->user()->tenant_id;

        return [
            'name' => [
                $branch ? 'sometimes' : 'required',
                'string',
                'max:120',
                Rule::unique('branches', 'name')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId))
                    ->ignore($branch?->id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'business_hours' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'manager_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->whereIn('id', DB::table('tenant_memberships')->select('user_id')->where('tenant_id', $tenantId)->whereNull('removed_at'))),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatsFor(int $branchId, int $tenantId, int $rango): array
    {
        $since = now()->subDays($rango);

        return [
            'contacts_total' => Contact::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->count(),
            'conversations_open' => Conversation::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('status', 'open')
                ->count(),
            'opportunities_count' => Opportunity::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('created_at', '>=', $since)
                ->count(),
            'opportunities_value' => (float) Opportunity::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('created_at', '>=', $since)
                ->sum('value'),
            'opportunities_won' => Opportunity::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->where('status', 'won')
                ->where('created_at', '>=', $since)
                ->count(),
        ];
    }
}
