<?php

namespace App\Http\Controllers\Api;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\ChannelType;
use App\Enums\TemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBroadcastRequest;
use App\Http\Resources\BroadcastCampaignResource;
use App\Http\Resources\BroadcastRecipientResultResource;
use App\Jobs\DispatchBroadcastCampaignJob;
use App\Models\BroadcastCampaign;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastAudienceService;
use App\Services\WhatsAppMessagingLimitService;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BroadcastCampaignController extends Controller
{
    public function __construct(
        private readonly BroadcastAudienceService $audienceService,
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
                ->whereDoesntHave('message', fn ($q) => $q->where(function ($q) {
                    $q->whereNotNull('delivered_at')->orWhereNotNull('read_at');
                }))->count(),
            'pending_count' => (clone $recipients)->whereNull('sent_at')->whereIn('status', [BroadcastRecipientStatus::Pending, BroadcastRecipientStatus::Queued])->count(),
            'unconfirmed_count' => (clone $recipients)->whereNotNull('sent_at')->where('status', '!=', BroadcastRecipientStatus::Failed)
                ->whereDoesntHave('message', fn ($q) => $q->where(function ($q) {
                    $q->whereNotNull('delivered_at')->orWhereNotNull('read_at');
                }))->count(),
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
        $filters = $request->validated('filters', []);
        $includeWithoutConsent = (bool) $request->validated('include_without_consent', false);
        $resolved = $this->audienceService->resolveForCategory(
            $request->user(),
            $channel->id,
            $filters,
            $template->category,
            $includeWithoutConsent,
        );

        $consentedCount = $resolved['consented']->count();
        $withoutConsentCount = $resolved['without_consent']->count();

        // La misma selección que hace store(): sin include_without_consent,
        // los contactos sin consentimiento no cuentan para audience_count,
        // costo, capped ni messaging_limit — store() los va a excluir, así
        // que el estimate no puede mostrarlos como si fueran a enviarse ni
        // forzar el reconocimiento de un riesgo que el usuario no eligió
        // correr. consented_count/without_consent_count siguen informando el
        // desglose completo para que el front ofrezca incluirlos.
        $audience = $includeWithoutConsent
            ? $resolved['consented']->merge($resolved['without_consent'])
            : $resolved['consented'];
        $count = $audience->count();

        $messagingLimit = $this->messagingLimitService->forConfig($channel->whatsappConfig);
        $withoutConversationCount = $this->countWithoutConversation($audience, $channel->id);

        return response()->json([
            'data' => [
                'audience_count' => $count,
                'total_contacts_with_phone' => $resolved['total_contacts_with_phone'],
                'consented_count' => $consentedCount,
                'without_consent_count' => $withoutConsentCount,
                'contacts_without_conversation_count' => $withoutConversationCount,
                // Meta no entrega marketing a números de EE.UU. desde el
                // 2025-04-01. Se informa para que el usuario entienda por qué
                // la audiencia es menor que la que arrojan sus filtros.
                'excluded_us_count' => $resolved['excluded_us_count'],
                'excluded_duplicate_count' => $resolved['excluded_duplicate_count'],
                'filters_applied' => [
                    // pipeline_stage_id vive en Conversation: filtrar por
                    // etapa excluye implícitamente a quienes no tienen
                    // conversación. Sin este aviso, el usuario ve una
                    // audiencia mucho menor a la del tenant y no entiende por
                    // qué — es el modo de falla más probable del cambio.
                    'pipeline_stage_restricts_to_existing_conversations' => ! empty($filters['pipeline_stage_id']),
                ],
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
        $includeWithoutConsent = (bool) $request->validated('include_without_consent', false);
        $resolved = $this->audienceService->resolveForCategory($request->user(), $channel->id, $filters, $template->category, $includeWithoutConsent);

        $consented = $resolved['consented'];
        $withoutConsent = $resolved['without_consent'];
        $audience = $includeWithoutConsent ? $consented->merge($withoutConsent) : $consented;

        if ($audience->isEmpty()) {
            // Distinguir los distintos motivos de audiencia vacía: sin esto
            // el rechazo parece un error del sistema en vez de la causa real.
            if ($withoutConsent->isNotEmpty() && ! $includeWithoutConsent) {
                return response()->json([
                    'message' => 'Todos los contactos de esta audiencia no tienen consentimiento registrado para marketing. Marcá "incluir sin consentimiento" para enviarles igual.',
                ], 422);
            }

            if ($resolved['excluded_us_count'] > 0) {
                return response()->json([
                    'message' => 'Todos los contactos de esta audiencia tienen números de Estados Unidos, y Meta no entrega plantillas de marketing a ese país.',
                ], 422);
            }

            return response()->json(['message' => 'No hay contactos que coincidan con la audiencia seleccionada.'], 422);
        }

        // El aviso protege al cliente de la sorpresa, no del bloqueo: Meta no
        // consulta si hubo advertencia. Por eso se exige cuantificado y se
        // registra quién lo aceptó — respalda al proveedor ante un reclamo.
        if ($includeWithoutConsent
            && $withoutConsent->isNotEmpty()
            && ! $request->validated('acknowledge_consent_risk', false)) {
            return response()->json([
                'message' => 'Esta difusión incluye contactos que no dieron consentimiento para recibir mensajes de marketing.',
                'consent_warning' => [
                    'without_consent_count' => $withoutConsent->count(),
                    'consented_count' => $consented->count(),
                    'risks' => [
                        'Meta puede bloquear el envío de plantillas por 1 a 3 días.',
                        'Ante reincidencia, el bloqueo alcanza 5, 7 o 30 días para todos los mensajes.',
                        'En casos graves, la cuenta queda bloqueada de forma indefinida y solo se recupera por apelación.',
                        'La plantilla puede pausarse (3h, luego 6h) y deshabilitarse de forma permanente.',
                        'El daño de calidad afecta a todos los números de la cartera, no solo al emisor.',
                    ],
                ],
            ], 422);
        }

        $messagingLimit = $this->messagingLimitService->forConfig($channel->whatsappConfig);
        if ($messagingLimit['known']
            && $messagingLimit['limit'] !== null
            && $audience->count() > $messagingLimit['limit']
            && ! $request->validated('acknowledge_messaging_limit', false)) {
            return response()->json([
                'message' => "Esta difusión tiene {$audience->count()} destinatarios y el límite de mensajería de tu cartera de Meta es de {$messagingLimit['limit']} en 24 horas. El límite lo comparten todos tus números: los mensajes que lo excedan van a fallar.",
                'messaging_limit' => $messagingLimit,
            ], 422);
        }

        $confirmationThreshold = (int) config('broadcasts.confirmation_threshold');
        if ($audience->count() > $confirmationThreshold
            && ! $request->validated('acknowledge_audience_size', false)) {
            return response()->json([
                'message' => "Esta difusión va a {$audience->count()} contactos, con un costo estimado de USD ".round($audience->count() * (float) config('broadcasts.cost_per_message_usd'), 2).'. Confirmá que querés continuar.',
                'audience_count' => $audience->count(),
            ], 422);
        }

        $intervalSeconds = (int) $request->validated('interval_seconds');
        $maxDuration = (int) config('broadcasts.max_campaign_duration_seconds');
        if ($intervalSeconds > 0 && $audience->count() * $intervalSeconds > $maxDuration) {
            return response()->json([
                'message' => 'Con este intervalo, la difusión tardaría más de lo permitido en completarse. Elegí un intervalo menor o reducí la audiencia.',
            ], 422);
        }

        $duplicatePhoneCount = $resolved['excluded_duplicate_count'];
        $withoutConsentCount = $includeWithoutConsent ? $withoutConsent->count() : 0;

        $campaign = DB::transaction(function () use (
            $request,
            $channel,
            $template,
            $filters,
            $audience,
            $duplicatePhoneCount,
            $withoutConsentCount,
            $includeWithoutConsent,
        ): BroadcastCampaign {
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
                'duplicate_phone_count' => $duplicatePhoneCount,
                'without_consent_count' => $withoutConsentCount,
                'consent_warning_accepted_by' => $includeWithoutConsent && $withoutConsentCount > 0 ? $request->user()->id : null,
                'consent_warning_accepted_at' => $includeWithoutConsent && $withoutConsentCount > 0 ? now() : null,
                'estimated_cost_usd' => round($audience->count() * (float) config('broadcasts.cost_per_message_usd'), 2),
                'interval_seconds' => $request->validated('interval_seconds'),
                'scheduled_at' => $scheduledAt,
                'results_tracking_version' => 1,
            ]);

            // insert() en chunks, no createMany(): con audiencias de miles de
            // contactos un INSERT por fila no entra en el timeout del request.
            foreach ($audience->chunk(500) as $chunk) {
                BroadcastRecipient::insert($chunk->map(fn (Contact $contact): array => [
                    'broadcast_campaign_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'conversation_id' => null,
                    'phone_normalized' => PhoneNumberNormalizer::dedupeKey($contact->phone),
                    'status' => BroadcastRecipientStatus::Pending->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            }

            return $campaign;
        });

        Log::info('BroadcastCampaign creada', [
            'campaign_id' => $campaign->id,
            'tenant_id' => $campaign->tenant_id,
            'user_id' => $request->user()->id,
            'audience_count' => $campaign->audience_count,
            'without_consent_count' => $withoutConsentCount,
            'estimated_cost_usd' => (float) $campaign->estimated_cost_usd,
        ]);

        if ($request->validated('launch') === 'now') {
            // El dispatch sale del request: con miles de destinatarios,
            // despachar un job por cada uno de forma sincrónica también
            // agota el timeout de PHP-FPM.
            DispatchBroadcastCampaignJob::dispatch($campaign->id);
        }

        $campaign = $this->campaignQuery()->findOrFail($campaign->id);

        return response()->json(['data' => new BroadcastCampaignResource($campaign)], 201);
    }

    /** @param Collection<int, Contact> $audience */
    private function countWithoutConversation(Collection $audience, int $channelId): int
    {
        if ($audience->isEmpty()) {
            return 0;
        }

        $withConversation = Contact::query()
            ->whereIn('id', $audience->pluck('id'))
            ->whereHas('conversations', fn ($query) => $query->where('channel_id', $channelId))
            ->count();

        return $audience->count() - $withConversation;
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
