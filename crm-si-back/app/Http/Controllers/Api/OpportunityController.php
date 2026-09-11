<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\User;
use App\Support\BranchRuleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\JsonResponse;

class OpportunityController extends Controller
{
    private const EAGER_LOAD = [
        'contact:id,name,phone,email,source',
        'conversation:id,contact_id,channel_id,last_message_content,last_message_at',
        'conversation.channel:id,name,type',
        'pipelineStage:id,name,sort_order,is_default',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Opportunity::class);

        $user = $request->user();

        $query = Opportunity::query()
            ->with(self::EAGER_LOAD)
            ->visibleTo($user)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('updated_at');

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->integer('contact_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('branch_id') && ($user->isTenantOwner() || $user->can('branches.view_all'))) {
            $query->where('branch_id', (int) $request->query('branch_id'));
        }

        $opportunities = $query->paginate((int) $request->query('per_page', 100));

        return response()->json([
            'data' => $opportunities->items(),
            'meta' => [
                'total' => $opportunities->total(),
                'current_page' => $opportunities->currentPage(),
                'last_page' => $opportunities->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Opportunity::class);
        $validated = $request->validate($this->rules($request));
        $contact = Contact::findOrFail($validated['contact_id']);
        $conversation = $this->resolveConversation($validated, $contact->id);
        $stage = $this->resolveStage($validated);
        $assignedUser = $this->resolveAssignedUser($validated);

        $payload = [
            'contact_id' => $contact->id,
            'conversation_id' => $conversation?->id,
            'pipeline_stage_id' => $stage?->id,
            'assigned_to' => $assignedUser?->id,
            'title' => $validated['title'] ?? ('Oportunidad - '.$contact->name),
            'status' => $validated['status'] ?? 'open',
            'source_type' => $conversation ? 'conversation' : ($validated['source_type'] ?? 'manual'),
            'value' => $validated['value'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'last_activity_at' => $validated['last_activity_at'] ?? ($conversation?->last_message_at ?? now()),
        ];

        if (array_key_exists('branch_id', $validated)) {
            $payload['branch_id'] = $validated['branch_id'];
        }

        $opportunity = Opportunity::create($payload);

        $opportunity->load(self::EAGER_LOAD);

        return response()->json(['data' => $opportunity], 201);
    }

    public function show($id): JsonResponse
    {
        $opportunity = Opportunity::with(self::EAGER_LOAD)->findOrFail($id);
        $this->authorize('view', $opportunity);

        return response()->json(['data' => $opportunity]);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $opportunity = Opportunity::findOrFail($id);
        $this->authorize('update', $opportunity);
        $validated = $request->validate($this->rules($request, $opportunity));

        $contactId = $validated['contact_id'] ?? $opportunity->contact_id;

        if (array_key_exists('contact_id', $validated)) {
            $contact = Contact::findOrFail($validated['contact_id']);
            $opportunity->contact()->associate($contact);
        }

        if (array_key_exists('conversation_id', $validated)) {
            $conversation = $this->resolveConversation($validated, (int) $contactId);
            $opportunity->conversation()->associate($conversation);

            if ($conversation) {
                $opportunity->source_type = 'conversation';
            }
        }

        if (array_key_exists('pipeline_stage_id', $validated)) {
            $opportunity->pipelineStage()->associate($this->resolveStage($validated));
        }

        if (array_key_exists('assigned_to', $validated)) {
            $opportunity->assignedUser()->associate($this->resolveAssignedUser($validated));
        }

        $opportunity->fill(collect($validated)->except(['contact_id', 'conversation_id', 'pipeline_stage_id', 'assigned_to'])->all());
        $opportunity->save();

        $opportunity->load(self::EAGER_LOAD);

        return response()->json(['data' => $opportunity]);
    }

    public function destroy($id): JsonResponse
    {
        $opportunity = Opportunity::findOrFail($id);
        $this->authorize('delete', $opportunity);
        $opportunity->delete();

        return response()->json(['message' => 'Oportunidad eliminada']);
    }

    public function updateStage(Request $request, $id): JsonResponse
    {
        $opportunity = Opportunity::findOrFail($id);
        $this->authorize('changeStage', $opportunity);

        $validated = $request->validate([
            'pipeline_stage_id' => [
                'nullable',
                Rule::exists('pipeline_stages', 'id')->where(fn ($query) => $query->where('tenant_id', $request->user()->tenant_id)),
            ],
        ]);

        $stageId = $this->resolveStage($validated)?->id;

        $opportunity->update([
            'pipeline_stage_id' => $stageId,
        ]);

        // Sincronizar la Conversation vinculada (si existe)
        if ($opportunity->conversation_id) {
            Conversation::where('id', $opportunity->conversation_id)
                ->update(['pipeline_stage_id' => $stageId]);
        }

        $opportunity->load(self::EAGER_LOAD);

        return response()->json(['data' => $opportunity]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewSummary', Opportunity::class);

        $user = $request->user();
        $base = Opportunity::query()->visibleTo($user);

        $total = (clone $base)->count();
        $pipelineValue = (float) (clone $base)->sum('value');
        $wonValue = (float) (clone $base)->where('status', 'won')->sum('value');
        $wonCount = (clone $base)->where('status', 'won')->count();
        $weightedValue = $total > 0 ? $pipelineValue * ($wonCount / max($total, 1)) : 0.0;
        $avgValue = $total > 0 ? $pipelineValue / $total : 0.0;

        return response()->json([
            'total_opportunities' => $total,
            'pipeline_value' => round($pipelineValue, 2),
            'weighted_value' => round($weightedValue, 2),
            'avg_value' => round($avgValue, 2),
            'won_value' => round($wonValue, 2),
            'won_count' => $wonCount,
        ]);
    }

    private function rules(Request $request, ?Opportunity $opportunity = null): array
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;
        $sometimes = $opportunity ? ['sometimes'] : ['required'];

        return [
            'contact_id' => [...$sometimes, 'integer', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'conversation_id' => ['nullable', 'integer', Rule::exists('conversations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'pipeline_stage_id' => ['nullable', 'integer', Rule::exists('pipeline_stages', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('id', DB::table('tenant_memberships')->select('user_id')->where('tenant_id', $tenantId)->whereNull('removed_at')))],
            'title' => [$opportunity ? 'sometimes' : 'nullable', 'string', 'max:255'],
            'status' => [$opportunity ? 'sometimes' : 'nullable', Rule::in(['open', 'won', 'lost', 'archived'])],
            'source_type' => [$opportunity ? 'sometimes' : 'nullable', Rule::in(['manual', 'conversation'])],
            'value' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'last_activity_at' => ['nullable', 'date'],
            'branch_id' => BranchRuleResolver::rulesFor(
                $user,
                __('No tienes permiso para asignar oportunidades a esa sucursal.')
            ),
        ];
    }

    private function resolveConversation(array $validated, int $contactId): ?Conversation
    {
        if (! array_key_exists('conversation_id', $validated) || is_null($validated['conversation_id'])) {
            return null;
        }

        $conversation = Conversation::findOrFail($validated['conversation_id']);

        if ((int) $conversation->contact_id !== $contactId) {
            abort(422, 'La conversación no pertenece al contacto indicado');
        }

        return $conversation;
    }

    private function resolveStage(array $validated): ?PipelineStage
    {
        if (! array_key_exists('pipeline_stage_id', $validated) || is_null($validated['pipeline_stage_id'])) {
            return null;
        }

        return PipelineStage::findOrFail($validated['pipeline_stage_id']);
    }

    private function resolveAssignedUser(array $validated): ?User
    {
        if (! array_key_exists('assigned_to', $validated) || is_null($validated['assigned_to'])) {
            return null;
        }

        return User::withoutGlobalScopes()->whereKey($validated['assigned_to'])
            ->whereHas('memberships', fn ($query) => $query->where('tenant_id', request()->user()->tenant_id)->whereNull('removed_at'))
            ->firstOrFail();
    }
}
