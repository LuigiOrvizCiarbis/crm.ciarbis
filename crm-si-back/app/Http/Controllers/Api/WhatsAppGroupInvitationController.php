<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppGroupInvitationService;
use App\Services\WhatsAppTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WhatsAppGroupInvitationController extends Controller
{
    public function __construct(
        private readonly WhatsAppGroupInvitationService $invitationService,
        private readonly WhatsAppTemplateService $templateService,
    ) {}

    public function templates(WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('view', $group);

        $waConfig = $group->channel->whatsappConfig;
        if (! $waConfig) {
            return response()->json(['data' => []]);
        }

        $templates = $this->templateService->findGroupInviteTemplates($waConfig->id);

        return response()->json(['data' => $templates->values()]);
    }

    public function store(Request $request, WhatsAppGroup $group): JsonResponse
    {
        $this->authorize('invite', $group);

        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'template_id' => [
                'required',
                'integer',
                Rule::exists('whatsapp_templates', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'invitees' => ['required', 'array', 'min:1', 'max:7'],
            'invitees.*.contact_id' => ['required_without:invitees.*.phone', 'integer'],
            'invitees.*.phone' => ['required_without:invitees.*.contact_id', 'string'],
            'invitees.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $template = WhatsAppTemplate::findOrFail($validated['template_id']);

        try {
            $participants = $this->invitationService->invite(
                $group,
                $template,
                $validated['invitees'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $participants], 201);
    }
}
