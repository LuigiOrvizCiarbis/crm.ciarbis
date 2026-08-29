<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWhatsAppGroupRequest;
use App\Http\Requests\UpdateWhatsAppGroupRequest;
use App\Http\Resources\WhatsAppGroupResource;
use App\Models\Channel;
use App\Models\WhatsAppGroup;
use App\Services\WhatsAppGroupEligibilityService;
use App\Services\WhatsAppGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppGroupController extends Controller
{
    public function __construct(
        private readonly WhatsAppGroupService $groupService,
        private readonly WhatsAppGroupEligibilityService $eligibilityService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WhatsAppGroup::class);

        $query = WhatsAppGroup::query()->with(['channel:id,name,type']);

        if ($request->filled('channel_id')) {
            $query->where('channel_id', $request->integer('channel_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->integer('conversation_id'));
        }

        $groups = $query->latest()->paginate(min((int) $request->query('per_page', 20), 100));

        return response()->json(WhatsAppGroupResource::collection($groups)->response()->getData(true));
    }

    public function store(StoreWhatsAppGroupRequest $request): JsonResponse
    {
        // El acceso al canal puntual ya lo valida StoreWhatsAppGroupRequest::authorize()
        // vía WhatsAppGroupPolicy::create($user, $channel) — no ChannelPolicy::view,
        // que un Member no pasa (memberPermissions() no incluye channels.*).
        $channel = Channel::query()->with('whatsappConfig')->findOrFail($request->validated('channel_id'));

        return $this->handle(function () use ($request, $channel): JsonResponse {
            $group = $this->groupService->createGroup(
                channel: $channel,
                subject: $request->validated('subject'),
                description: $request->validated('description'),
                joinApprovalMode: $request->validated('join_approval_mode', 'approval_required'),
                createdBy: $request->user()->id,
                opportunityId: $request->validated('opportunity_id'),
            );

            return response()->json(['data' => new WhatsAppGroupResource($group)], 201);
        });
    }

    public function show(WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('view', $group);

        $group->load(['participants' => fn ($query) => $query->orderBy('id')]);

        return response()->json(['data' => new WhatsAppGroupResource($group)]);
    }

    public function update(UpdateWhatsAppGroupRequest $request, WhatsAppGroup $group): JsonResponse
    {
        return $this->handle(function () use ($request, $group): JsonResponse {
            $this->groupService->updateGroupSettings($group, $request->validated());

            return response()->json(['data' => new WhatsAppGroupResource($group->fresh())]);
        });
    }

    public function destroy(WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('delete', $group);

        return $this->handle(function () use ($group): JsonResponse {
            $this->groupService->deleteGroup($group);

            if ($group->conversation) {
                $group->conversation->update(['archived_at' => now()]);
            }

            return response()->json(['data' => new WhatsAppGroupResource($group->fresh())]);
        });
    }

    public function sync(WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('view', $group);

        return $this->handle(function () use ($group): JsonResponse {
            $data = $this->groupService->fetchGroup($group);

            $group->update([
                'subject' => $data['subject'] ?? $group->subject,
                'description' => $data['description'] ?? $group->description,
                'join_approval_mode' => $data['join_approval_mode'] ?? $group->join_approval_mode,
                'suspended' => (bool) ($data['suspended'] ?? $group->suspended),
                'total_participant_count' => (int) ($data['total_participant_count'] ?? $group->total_participant_count),
                'last_synced_at' => now(),
            ]);

            return response()->json(['data' => new WhatsAppGroupResource($group->fresh())]);
        });
    }

    public function inviteLink(WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('view', $group);

        return $this->handle(function () use ($group): JsonResponse {
            $link = $group->invite_link ?? $this->groupService->fetchInviteLink($group);

            return response()->json(['data' => ['invite_link' => $link]]);
        });
    }

    public function resetInviteLink(WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('update', $group);

        return $this->handle(function () use ($group): JsonResponse {
            $link = $this->groupService->resetInviteLink($group);

            return response()->json(['data' => ['invite_link' => $link]]);
        });
    }

    public function joinRequests(WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('view', $group);

        return $this->handle(fn (): JsonResponse => response()->json(['data' => $this->groupService->listJoinRequests($group)]));
    }

    public function approveJoinRequests(Request $request, WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('manageParticipants', $group);

        $waIds = (array) $request->validate(['wa_ids' => ['required', 'array', 'min:1']])['wa_ids'];

        return $this->handle(function () use ($group, $waIds): JsonResponse {
            $this->groupService->approveJoinRequests($group, $waIds);

            return response()->json(['message' => 'Solicitudes aprobadas.']);
        });
    }

    public function rejectJoinRequests(Request $request, WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('manageParticipants', $group);

        $waIds = (array) $request->validate(['wa_ids' => ['required', 'array', 'min:1']])['wa_ids'];

        return $this->handle(function () use ($group, $waIds): JsonResponse {
            $this->groupService->rejectJoinRequests($group, $waIds);

            return response()->json(['message' => 'Solicitudes rechazadas.']);
        });
    }

    public function removeParticipants(Request $request, WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('manageParticipants', $group);

        $waIds = (array) $request->validate([
            'participants' => ['required', 'array', 'min:1', 'max:'.config('whatsapp_groups.max_participants')],
            'participants.*' => ['string'],
        ])['participants'];

        return $this->handle(function () use ($group, $waIds): JsonResponse {
            $this->groupService->removeParticipants($group, $waIds);

            $group->participants()->whereIn('wa_id', $waIds)->update([
                'status' => 'removed',
                'removed_at' => now(),
                'removed_by' => 'business',
            ]);

            return response()->json(['message' => 'Participantes eliminados.']);
        });
    }

    public function eligibility(Channel $channel): JsonResponse
    {
        $this->authorize('view', $channel);

        $waConfig = $channel->whatsappConfig;
        if (! $waConfig) {
            return response()->json([
                'data' => [
                    'status' => 'unknown',
                    'is_oba' => null,
                    'platform_type' => null,
                    'checked_at' => null,
                    'reason_message' => 'Este canal no tiene una configuración de WhatsApp asociada.',
                ],
            ]);
        }

        return response()->json(['data' => $this->eligibilityService->statusFor($waConfig)]);
    }

    /**
     * InvalidArgumentException = precondición del cliente (422, mensaje
     * accionable). RuntimeException = Meta rechazó el request (502, mensaje
     * de Meta). Sin este catch, el handler global de bootstrap/app.php oculta
     * cualquier excepción no capturada detrás de un 500 genérico.
     */
    private function handle(\Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // ModelNotFoundException extiende RuntimeException: sin este
            // catch antes, caería al 502 genérico en vez del 404 nativo.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }
}
