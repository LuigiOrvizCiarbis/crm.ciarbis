<?php

namespace App\Http\Controllers\Api;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\ChannelType;
use App\Enums\TemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBroadcastRequest;
use App\Http\Resources\BroadcastCampaignResource;
use App\Http\Resources\BroadcastRecipientResultResource;
use App\Models\BroadcastCampaign;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastAudienceService;
use App\Services\BroadcastDispatcher;
use App\Services\WhatsAppMessagingLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BroadcastCampaignController extends Controller
{
    public function __construct(
        private readonly BroadcastAudienceService $audienceService,
        private readonly BroadcastDispatcher $dispatcher,
        private readonly WhatsAppMessagingLimitService $messagingLimitService,
    ) {}

    public function index(Request $request)
    {
        abort_unless($request->user()?->can('templates.view'), 403);

        $campaigns = $this->campaignQuery()
            ->latest('scheduled_at')
            ->paginate(min((int) $request->query('per_page', 20), 100));

        return BroadcastCampaignResource::collection($campaigns);
    }

    public function results(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->can('templates.view'), 403);
        $campaign = BroadcastCampaign::query()->with(['channel:id,name,type', 'template:id,name,language'])->findOrFail($id);
        if (! $campaign->resultsEnabled()) {
            return response()->json(['data' => ['results_available' => false, 'campaign_id' => $campaign->id]]);
        }

        $recipients = $campaign->recipients();
        $summary = [
            'audience_count' => (int) $campaign->audience_count,
            'accepted_count' => (clone $recipients)->whereNotNull('sent_at')->count(),
            'delivered_count' => (clone $recipients)->whereHas('message', fn ($q) => $q->whereNotNull('delivered_at'))->count(),
            'read_count' => (clone $recipients)->whereHas('message', fn ($q) => $q->whereNotNull('read_at'))->count(),
            'failed_count' => (clone $recipients)->where('status', BroadcastRecipientStatus::Failed)
                ->whereDoesntHave('message', fn ($q) => $q->where(function ($q) { $q->whereNotNull('delivered_at')->orWhereNotNull('read_at'); }))->count(),
            'pending_count' => (clone $recipients)->whereNull('sent_at')->whereIn('status', [BroadcastRecipientStatus::Pending, BroadcastRecipientStatus::Queued])->count(),
            'unconfirmed_count' => (clone $recipients)->whereNotNull('sent_at')->where('status', '!=', BroadcastRecipientStatus::Failed)
                ->whereDoesntHave('message', fn ($q) => $q->where(function ($q) { $q->whereNotNull('delivered_at')->orWhereNotNull('read_at'); }))->count(),
            'interacted_count' => (clone $recipients)->whereHas('interactions')->distinct('broadcast_recipients.id')->count('broadcast_recipients.id'),
        ];

        return response()->json(['data' => [
            'results_available' => true,
            'campaign' => new BroadcastCampaignResource($campaign),
            'summary' => $summary,
        ]]);
    }

    public function recipients(Request $request, int $id)
    {
        abort_unless($request->user()?->can('templates.view'), 403);
        $campaign = BroadcastCampaign::query()->findOrFail($id);
        abort_unless($campaign->resultsEnabled(), 404);
        $query = $campaign->recipients()->with(['contact:id,name,phone', 'message:id,delivered_at,read_at', 'interactions']);
        if ($search = trim((string) $request->query('search', ''))) {
            $query->whereHas('contact', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            $query = $this->applyResultStatusFilter($query, $status);
        }
        if ($request->query('interaction') === 'true') {
            $query->whereHas('interactions');
        }
        return BroadcastRecipientResultResource::collection($query->orderBy('id')->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    public function recipient(Request $request, int $id, int $recipientId): JsonResponse
    {
        abort_unless($request->user()?->can('templates.view'), 403);
        $campaign = BroadcastCampaign::query()->findOrFail($id);
        abort_unless($campaign->resultsEnabled(), 404);
        $recipient = $campaign->recipients()->with(['contact:id,name,phone', 'message:id,delivered_at,read_at', 'interactions' => fn ($q) => $q->orderBy('occurred_at')])->findOrFail($recipientId);
        return response()->json(['data' => new BroadcastRecipientResultResource($recipient), 'history' => $recipient->interactions->map(fn ($item) => [
            'type' => $item->type, 'value' => $item->value, 'content' => $item->content, 'occurred_at' => $item->occurred_at?->toIso8601String(),
        ])->values()]);
    }

    private function applyResultStatusFilter($query, string $status)
    {
        return match ($status) {
            'failed' => $query->where('status', BroadcastRecipientStatus::Failed),
            'pending' => $query->whereNull('sent_at')->whereIn('status', [BroadcastRecipientStatus::Pending, BroadcastRecipientStatus::Queued]),
            'accepted_unconfirmed' => $query->whereNotNull('sent_at')->where('status', '!=', BroadcastRecipientStatus::Failed)->whereDoesntHave('message', fn ($q) => $q->whereNotNull('delivered_at')),
            'delivered' => $query->whereHas('message', fn ($q) => $q->whereNotNull('delivered_at')->whereNull('read_at')),
            'read' => $query->whereHas('message', fn ($q) => $q->whereNotNull('read_at')),
            default => $query,
        };
    }

    public function estimate(StoreBroadcastRequest $request): JsonResponse
    {
        [$channel, $template] = $this->validateChannelAndTemplate($request);
        $resolved = $this->audienceService->resolveForCategory(
            $request->user(),
            $channel->id,
            $request->validated('filters', []),
            $template->category,
        );
        $count = $resolved['audience']->count();

        $messagingLimit = $this->messagingLimitService->forConfig($channel->whatsappConfig);

        return response()->json([
            'data' => [
                'audience_count' => $count,
                // Meta no entrega marketing a números de EE.UU. desde el
                // 2025-04-01. Se informa para que el usuario entienda por qué
                // la audiencia es menor que la que arrojan sus filtros.
                'excluded_us_count' => $resolved['excluded_us_count'],
                'estimated_cost_usd' => round($count * (float) config('broadcasts.cost_per_message_usd'), 2),
                'capped' => $count >= (int) config('broadcasts.max_recipients'),
                // Techo de Meta para envíos fuera de ventana en 24h. Compartido
                // por toda la cartera, así que otros números pueden haber
                // consumido parte antes de que salga esta difusión.
                'messaging_limit' => [
                    'known' => $messagingLimit['known'],
                    'tier' => $messagingLimit['tier'],
                    'limit' => $messagingLimit['limit'],
                    'unlimited' => $messagingLimit['unlimited'],
                    'exceeded' => $messagingLimit['known']
                        && $messagingLimit['limit'] !== null
                        && $count > $messagingLimit['limit'],
                ],
            ],
        ]);
    }

    public function store(StoreBroadcastRequest $request): JsonResponse
    {
        [$channel, $template] = $this->validateChannelAndTemplate($request);
        $filters = $request->validated('filters', []);
        $resolved = $this->audienceService->resolveForCategory($request->user(), $channel->id, $filters, $template->category);
        $audience = $resolved['audience'];

        if ($audience->isEmpty()) {
            // Distinguir "no hay contactos" de "los había, pero eran todos de
            // EE.UU.": el usuario ve contactos en sus filtros y sin este
            // mensaje el rechazo parecería un error del sistema.
            if ($resolved['excluded_us_count'] > 0) {
                return response()->json([
                    'message' => 'Todos los contactos de esta audiencia tienen números de Estados Unidos, y Meta no entrega plantillas de marketing a ese país.',
                ], 422);
            }

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
                'results_tracking_version' => 1,
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

        if ($template->whatsapp_config_id !== $channel->whatsappConfig->id) {
            abort(422, 'La plantilla no pertenece al canal seleccionado.');
        }

        // Meta puede pausar o deshabilitar una plantilla en cualquier momento por
        // feedback negativo, así que una que estaba aprobada al abrir el diálogo
        // puede no estarlo al confirmar. El motivo concreto evita que el usuario
        // crea que se trata de una plantilla que nunca se aprobó.
        if (! $template->status->isApproved()) {
            abort(422, $this->templateRejectionMessage($template->status));
        }

        return [$channel, $template];
    }

    private function templateRejectionMessage(TemplateStatus $status): string
    {
        return match ($status) {
            TemplateStatus::Paused => 'Meta pausó esta plantilla por feedback negativo de los usuarios. Vas a poder volver a usarla cuando Meta la reactive.',
            TemplateStatus::Disabled => 'Meta deshabilitó esta plantilla de forma permanente. Creá una nueva para esta difusión.',
            TemplateStatus::LimitExceeded => 'Esta plantilla alcanzó su límite de envíos en Meta.',
            TemplateStatus::Rejected => 'Meta rechazó esta plantilla. Revisá el motivo en Configuración y creá una nueva.',
            TemplateStatus::Pending, TemplateStatus::InAppeal => 'Meta todavía está revisando esta plantilla. Vas a poder enviarla cuando la apruebe.',
            TemplateStatus::PendingDeletion, TemplateStatus::Deleted => 'Esta plantilla fue eliminada de Meta.',
            default => 'Esta plantilla no está aprobada para enviar. Sincronizá las plantillas para ver su estado actual.',
        };
    }
}
