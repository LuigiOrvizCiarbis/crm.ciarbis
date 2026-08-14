<?php

namespace App\Http\Controllers\Api;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBroadcastRequest;
use App\Http\Resources\BroadcastCampaignResource;
use App\Models\BroadcastCampaign;
use App\Models\Channel;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastAudienceService;
use App\Services\BroadcastDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BroadcastCampaignController extends Controller
{
    public function __construct(
        private readonly BroadcastAudienceService $audienceService,
        private readonly BroadcastDispatcher $dispatcher,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()?->can('templates.view'), 403);

        $campaigns = $this->campaignQuery()
            ->latest('scheduled_at')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return BroadcastCampaignResource::collection($campaigns);
    }

    public function estimate(StoreBroadcastRequest $request): JsonResponse
    {
        [$channel] = $this->validateChannelAndTemplate($request);
        $audience = $this->audienceService->resolve($request->user(), $channel->id, $request->validated('filters', []));
        $count = $audience->count();

        return response()->json([
            'data' => [
                'audience_count' => $count,
                'estimated_cost_usd' => round($count * (float) config('broadcasts.cost_per_message_usd'), 2),
                'capped' => $count >= (int) config('broadcasts.max_recipients'),
            ],
        ]);
    }

    public function store(StoreBroadcastRequest $request): JsonResponse
    {
        [$channel, $template] = $this->validateChannelAndTemplate($request);
        $filters = $request->validated('filters', []);
        $audience = $this->audienceService->resolve($request->user(), $channel->id, $filters);

        if ($audience->isEmpty()) {
            return response()->json(['message' => 'No hay contactos que coincidan con la audiencia seleccionada.'], 422);
        }

        $campaign = DB::transaction(function () use ($request, $channel, $template, $filters, $audience): BroadcastCampaign {
            $scheduledAt = $request->validated('launch') === 'now'
                ? now()
                : Carbon::parse($request->validated('scheduled_at'));

            $campaign = BroadcastCampaign::create([
                'tenant_id' => $request->user()->tenant_id,
                'channel_id' => $channel->id,
                'whatsapp_template_id' => $template->id,
                'created_by' => $request->user()->id,
                'name' => $request->validated('name'),
                'audience_filters' => $filters,
                'components' => $request->validated('components', []),
                'audience_count' => $audience->count(),
                'estimated_cost_usd' => round($audience->count() * (float) config('broadcasts.cost_per_message_usd'), 2),
                'interval_seconds' => $request->validated('interval_seconds'),
                'scheduled_at' => $scheduledAt,
            ]);

            $campaign->recipients()->createMany($audience->map(fn ($conversation): array => [
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
            ])->all());

            return $campaign;
        });

        if ($request->validated('launch') === 'now') {
            $this->dispatcher->dispatch($campaign);
        }

        $campaign = $this->campaignQuery()->findOrFail($campaign->id);

        return response()->json(['data' => new BroadcastCampaignResource($campaign)], 201);
    }

    private function campaignQuery()
    {
        return BroadcastCampaign::query()
            ->with(['channel:id,name,type', 'template:id,name,language'])
            ->withCount([
                'recipients as sent_count' => fn ($query) => $query->where('status', BroadcastRecipientStatus::Sent),
                'recipients as error_count' => fn ($query) => $query->where('status', BroadcastRecipientStatus::Failed),
                'recipients as pending_count' => fn ($query) => $query->whereIn('status', [
                    BroadcastRecipientStatus::Pending,
                    BroadcastRecipientStatus::Queued,
                ]),
            ]);
    }

    /** @return array{0: Channel, 1: WhatsAppTemplate} */
    private function validateChannelAndTemplate(StoreBroadcastRequest $request): array
    {
        $channel = Channel::query()->with('whatsappConfig')->findOrFail($request->validated('channel_id'));
        $this->authorize('view', $channel);

        if ($channel->type !== ChannelType::WHATSAPP || $channel->status !== 'active' || ! $channel->whatsappConfig) {
            abort(422, 'La difusión requiere un canal de WhatsApp activo.');
        }

        $template = WhatsAppTemplate::query()->findOrFail($request->validated('template_id'));

        if (! $template->status->isApproved() || $template->whatsapp_config_id !== $channel->whatsappConfig->id) {
            abort(422, 'La plantilla debe estar aprobada y pertenecer al canal seleccionado.');
        }

        return [$channel, $template];
    }
}
