<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageDirection;
use App\Http\Controllers\Controller;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ManualAiDraft;
use App\Events\ManualAiDraftUpdated;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppMessageService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\JsonResponse;

class ConversationController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Conversation::class);

        $request->validate([
            'search' => 'sometimes|string|min:2|max:100',
        ]);

        $contactId = $request->query('contact_id');
        $channelId = $request->query('channel_id');
        $filterByUserId = $request->query('user_id');

        $user = $request->user();
        $canViewAny = $user->can('conversations.view_any');

        $q = Conversation::query()
            ->with(['contact:id,name,phone', 'channel:id,name,type', 'messages:id', 'tags'])
            ->withCount(['messages as unread_count' => function ($query) {
                $query->where('direction', MessageDirection::INBOUND)
                    ->whereNull('mail_parent_message_id')
                    ->whereNull('read_at')
                    ->whereNull('deleted_at');
            }])
            ->visibleTo($user);

        if ($canViewAny && $filterByUserId) {
            $q->where(function ($query) use ($filterByUserId) {
                $query->whereHas('users', function ($q) use ($filterByUserId) {
                    $q->where('users.id', $filterByUserId);
                })
                    ->orWhereHas('channel', function ($q) use ($filterByUserId) {
                        $q->where('user_id', $filterByUserId);
                    });
            });
        }

        if ($contactId) {
            $q->where('contact_id', $contactId);
        }
        if ($channelId) {
            $channel = Channel::with('users:id')->findOrFail($channelId);
            $channelUserIds = $channel->users
                ->pluck('id')
                ->push($channel->user_id)
                ->filter()
                ->unique()
                ->values()
                ->all();

            $q->where(function ($query) use ($channelId, $channelUserIds) {
                $query->where('channel_id', $channelId);

                if ($channelUserIds !== []) {
                    $query->orWhereIn('assigned_to', $channelUserIds)
                        ->orWhereHas('users', function ($userQuery) use ($channelUserIds) {
                            $userQuery->whereIn('users.id', $channelUserIds);
                        });
                }
            });
        }

        if ($request->filled('tags')) {
            $q->withTagSlugs($this->parseTagSlugs((string) $request->query('tags')));
        }

        if ($request->filled('branch_id') && ($user->isTenantOwner() || $user->can('branches.view_all'))) {
            $q->where('conversations.branch_id', (int) $request->query('branch_id'));
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && mb_strlen($search) >= 2) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            // ilike usa el índice pg_trgm en Postgres; en SQLite (tests) LIKE ya es case-insensitive
            $likeOperator = Conversation::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            // ESCAPE explícito: SQLite no tiene carácter de escape por defecto en LIKE.
            // La relación messages() usa withTrashed(), por eso el whereNull explícito.
            $q->whereHas('messages', function ($mq) use ($escaped, $likeOperator) {
                $mq->whereNull('messages.deleted_at')
                    ->where(function ($contentQuery) use ($escaped, $likeOperator) {
                        $contentQuery
                            ->whereRaw("content {$likeOperator} ? escape '\\'", ["%{$escaped}%"])
                            ->orWhereHas('mailDetails', function ($detailQuery) use ($escaped, $likeOperator) {
                                $detailQuery->whereRaw("subject {$likeOperator} ? escape '\\'", ["%{$escaped}%"]);
                            });
                    });
            });

            $q->select('conversations.*')->addSelect([
                'matched_message_snippet' => Message::select('content')
                    ->whereColumn('messages.conversation_id', 'conversations.id')
                    ->whereRaw("content {$likeOperator} ? escape '\\'", ["%{$escaped}%"])
                    ->orderByDesc('created_at')
                    ->limit(1),
            ]);
        }

        if ($request->query('summary') === 'unread_count') {
            $messageUnreadCount = (clone $q)
                ->reorder()
                ->join('messages', 'messages.conversation_id', '=', 'conversations.id')
                ->where('messages.direction', MessageDirection::INBOUND->value)
                ->whereNull('messages.mail_parent_message_id')
                ->whereNull('messages.read_at')
                ->whereNull('messages.deleted_at')
                ->count('messages.id');

            $manualUnreadCount = (clone $q)
                ->reorder()
                ->where('conversations.manual_unread', true)
                ->whereDoesntHave('messages', function ($query) {
                    $query->where('direction', MessageDirection::INBOUND)
                        ->whereNull('mail_parent_message_id')
                        ->whereNull('read_at')
                        ->whereNull('deleted_at');
                })
                ->count('conversations.id');

            return response()->json([
                'data' => [
                    'unread_count' => $messageUnreadCount + $manualUnreadCount,
                ],
            ]);
        }

        $q->orderByDesc('last_message_at');

        $conversations = $q->paginate((int) $request->query('per_page', 20));

        $data = $conversations->items();

        $transformed = array_map(function ($conversation) {
            return [
                'id' => $conversation->id,
                'channel_id' => $conversation->channel_id,
                'contact_id' => $conversation->contact_id,
                'contact' => $conversation->contact,
                'channel' => $conversation->channel,
                'last_message_at' => $conversation->last_message_at,
                'last_message' => $conversation->last_message_content ?? 'Sin mensajes',
                'unread_count' => $conversation->unread_count ?? 0,
                'manual_unread' => (bool) $conversation->manual_unread,
                'unread' => ($conversation->unread_count ?? 0) > 0 || (bool) $conversation->manual_unread,
                'lead_score' => $conversation->lead_score,
                'pipeline_stage_id' => $conversation->pipeline_stage_id,
                'assigned_to' => $conversation->assigned_to,
                'archived_at' => $conversation->archived_at,
                'archived' => $conversation->archived,
                'ai_autoreply_enabled' => (bool) $conversation->ai_autoreply_enabled,
                'contact_language' => $conversation->contact_language,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
                'messages' => $conversation->messages,
                'tags' => $conversation->tags,
                'matched_message_snippet' => $conversation->matched_message_snippet !== null
                    ? Str::limit($conversation->matched_message_snippet, 120)
                    : null,
            ];
        }, $data);

        return response()->json([
            'data' => $transformed,
            'meta' => [
                'total' => $conversations->total(),
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
            ],
        ]);
    }

    public function show($id): JsonResponse
    {

        $conversation = Conversation::with([
            'messages' => function ($q) {
                // CAMBIO SUGERIDO: Aumentar de 4 a 20 para llenar la pantalla inicial
                $q->with(['mailDetails', 'mailAttachments'])
                    ->whereNull('mail_parent_message_id')
                    ->orderBy('created_at', 'desc')
                    ->limit(20);
            },
            'contact:id,name,phone',
            'channel:id,name,type',
            'tags',
            'whatsappGroup:id,conversation_id,subject,status,group_id,total_participant_count,invite_link',
        ])
            ->findOrFail($id);

        $this->authorize('view', $conversation);

        $conversation->setRelation('messages', $conversation->messages->sortBy('created_at')->values());
        if ($conversation->isGroup()) {
            $this->attachSenderNames($conversation->messages);
        }

        // Agregar assigned_to explícitamente
        $data = $conversation->toArray();
        $data['assigned_to'] = $conversation->assigned_to;
        if ($conversation->relationLoaded('whatsappGroup') && $conversation->whatsappGroup) {
            $data['group'] = $conversation->whatsappGroup;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Adjunta `sender: {id, name}` a cada mensaje inbound de una conversación
     * de grupo. Message::sender() es un morphTo sin morph map registrado (el
     * valor guardado es el string plano 'contact', no una clase), así que no
     * se puede hacer ->load('sender'): se resuelve a mano en batch por
     * sender_id contra Contact.
     *
     * @param  \Illuminate\Support\Collection<int, Message>  $messages
     */
    private function attachSenderNames(\Illuminate\Support\Collection $messages): void
    {
        $contactIds = $messages
            ->where('sender_type', \App\Enums\SenderType::CONTACT)
            ->pluck('sender_id')
            ->filter()
            ->unique();

        if ($contactIds->isEmpty()) {
            return;
        }

        $names = \App\Models\Contact::whereIn('id', $contactIds)->pluck('name', 'id');

        $messages->each(function ($message) use ($names) {
            if ($message->sender_type === \App\Enums\SenderType::CONTACT && $names->has($message->sender_id)) {
                $message->setAttribute('sender', ['id' => $message->sender_id, 'name' => $names->get($message->sender_id)]);
            }
        });
    }

    // NUEVO MÉTODO: Obtener mensajes paginados (Infinite Scroll)
    public function fetchMessages(Request $request, $id)
    {
        $conversation = Conversation::findOrFail($id);
        $this->authorize('view', $conversation);

        $perPage = (int) $request->query('per_page', 20);

        // Obtenemos mensajes ordenados por fecha descendente (del más nuevo al más viejo)
        // Laravel Paginator se encarga de 'page=1', 'page=2', etc.
        $messages = $conversation->messages()
            ->with(['mailDetails', 'mailAttachments'])
            ->whereNull('mail_parent_message_id')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        if ($conversation->isGroup()) {
            $this->attachSenderNames($messages->getCollection());
        }

        return response()->json($messages);
    }

    public function updateStage(Request $request, $id)
    {
        $conversation = Conversation::where('id', $id)->firstOrFail();
        $this->authorize('changeStage', $conversation);

        $request->validate([
            'pipeline_stage_id' => 'required|exists:pipeline_stages,id',
        ]);

        // Validar que la etapa destino pertenezca al mismo tenant (seguridad extra)
        $stage = PipelineStage::where('id', $request->pipeline_stage_id)->firstOrFail();

        $conversation->update([
            'pipeline_stage_id' => $stage->id,
        ]);

        // Sincronizar la Opportunity vinculada: actualizar si existe, crear si no.
        // Una conversación de grupo no tiene contact_id (NOT NULL en
        // opportunities), así que no se autocrea una oportunidad ahí; si el
        // grupo nace desde una oportunidad existente, esa referencia vive en
        // whatsapp_groups.opportunity_id, no acá.
        $opportunity = Opportunity::where('conversation_id', $conversation->id)->first();

        if ($opportunity) {
            $opportunity->update([
                'pipeline_stage_id' => $stage->id,
                'last_activity_at' => now(),
            ]);
        } elseif (! $conversation->isGroup()) {
            Opportunity::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'pipeline_stage_id' => $stage->id,
                'title' => 'Oportunidad - '.($conversation->contact?->name ?? 'Sin nombre'),
                'status' => 'open',
                'source_type' => 'conversation',
                'last_activity_at' => now(),
            ]);
        }

        return response()->json($conversation);
    }

    /**
     * Asignar/derivar usuarios a una conversación
     */
    public function assignUsers(Request $request, $id)
    {
        $conversation = Conversation::where('id', $id)->firstOrFail();
        $this->authorize('assign', $conversation);

        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);
        $currentUserId = auth()->id();

        // Sincronizar usuarios con pivot data
        $syncData = [];
        foreach ($request->user_ids as $userId) {
            $syncData[$userId] = ['assigned_by' => $currentUserId];
        }

        $conversation->users()->sync($syncData);

        return response()->json([
            'message' => 'Usuarios asignados correctamente',
            'users' => $conversation->users,
        ]);
    }

    /**
     * Agregar un usuario adicional a la conversación
     */
    public function addUser(Request $request, $id)
    {
        $conversation = Conversation::where('id', $id)->firstOrFail();
        $this->authorize('assign', $conversation);

        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);
        $currentUserId = auth()->id();

        // Actualizar el responsable principal
        $conversation->update(['assigned_to' => $request->user_id]);

        // Attach sin eliminar existentes (para historial many-to-many)
        $conversation->users()->syncWithoutDetaching([
            $request->user_id => ['assigned_by' => $currentUserId],
        ]);

        return response()->json([
            'message' => 'Usuario asignado correctamente',
            'assigned_to' => $conversation->assigned_to,
            'users' => $conversation->users,
        ]);
    }

    /**
     * Remover un usuario de la conversación
     */
    public function removeUser(Request $request, $id, $userId)
    {
        $conversation = Conversation::where('id', $id)->firstOrFail();
        $this->authorize('assign', $conversation);

        $conversation->users()->detach($userId);

        return response()->json([
            'message' => 'Usuario removido correctamente',
            'users' => $conversation->users,
        ]);
    }

    public function markAsRead(Request $request, WhatsAppMessageService $whatsappService, $id): JsonResponse
    {
        $conversation = Conversation::with('channel')->where('id', $id)->firstOrFail();
        $this->authorize('view', $conversation);

        $unreadMessages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::INBOUND)
            ->whereNull('read_at')
            ->orderBy('created_at')
            ->get();

        $clearedManualFlag = false;
        if ($conversation->manual_unread) {
            $conversation->update(['manual_unread' => false]);
            $clearedManualFlag = true;
        }

        if ($unreadMessages->isEmpty()) {
            return response()->json([
                'data' => [
                    'conversation_id' => $conversation->id,
                    'unread_count' => 0,
                    'marked' => $clearedManualFlag ? 1 : 0,
                ],
            ]);
        }

        $now = now();
        Message::query()
            ->whereIn('id', $unreadMessages->pluck('id'))
            ->update(['read_at' => $now]);

        $latestExternalId = $unreadMessages
            ->reverse()
            ->pluck('external_id')
            ->first(fn ($id) => filled($id));

        if ($latestExternalId) {
            $whatsappService->markIncomingAsReadOnMeta($conversation, $latestExternalId);
        }

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'unread_count' => 0,
                'marked' => $unreadMessages->count(),
            ],
        ]);
    }

    public function markAsUnread(Request $request, $id): JsonResponse
    {
        $conversation = Conversation::where('id', $id)->firstOrFail();
        $this->authorize('view', $conversation);

        $conversation->update(['manual_unread' => true]);

        return response()->json([
            'data' => [
                'conversation_id' => $conversation->id,
                'unread_count' => 1,
                'marked' => 1,
            ],
        ]);
    }

    public function archive(Request $request, $id): JsonResponse
    {
        $conversation = Conversation::where('id', $id)->firstOrFail();
        $this->authorize('update', $conversation);

        $validated = $request->validate([
            'archived' => 'required|boolean',
        ]);

        if ($validated['archived']) {
            $conversation->archive();
        } else {
            $conversation->unarchive();
        }

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'archived' => $conversation->archived,
                'archived_at' => $conversation->archived_at,
            ],
        ]);
    }

    /**
     * Activa o desactiva la auto-respuesta de IA para la conversación.
     */
    public function aiAutoreply(Request $request, $id): JsonResponse
    {
        $conversation = Conversation::where('id', $id)->firstOrFail();
        $this->authorize('update', $conversation);

        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $conversation->update(['ai_autoreply_enabled' => $validated['enabled']]);

        if ($validated['enabled']) {
            $this->cancelManualAiDrafts($conversation);
        }

        return response()->json([
            'data' => [
                'id' => $conversation->id,
                'ai_autoreply_enabled' => $conversation->ai_autoreply_enabled,
            ],
        ]);
    }

    public function bulkTags(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:add,remove,replace'],
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ]);

        if (in_array($validated['action'], ['add', 'remove'], true) && count($validated['tag_ids']) === 0) {
            return response()->json([
                'message' => 'Debe seleccionar al menos una etiqueta.',
                'errors' => ['tag_ids' => ['Seleccione al menos una etiqueta.']],
            ], 422);
        }

        $conversations = Conversation::query()->whereIn('id', $validated['ids'])->get();

        $authorized = $conversations->filter(fn (Conversation $conversation) => $user->can('update', $conversation));

        $pivotData = collect($validated['tag_ids'])->mapWithKeys(fn (int $tagId) => [
            $tagId => [
                'tenant_id' => $tenantId,
                'assigned_by' => $user->id,
            ],
        ])->all();

        foreach ($authorized as $conversation) {
            match ($validated['action']) {
                'add' => $conversation->tags()->syncWithoutDetaching($pivotData),
                'remove' => $conversation->tags()->detach($validated['tag_ids']),
                'replace' => $conversation->tags()->sync($pivotData),
            };
        }

        return response()->json([
            'updated' => $authorized->count(),
            'failed' => count($validated['ids']) - $authorized->count(),
            'action' => $validated['action'],
        ]);
    }

    public function bulkAssign(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ]);

        $conversations = Conversation::query()->whereIn('id', $validated['ids'])->get();

        $authorized = $conversations->filter(fn (Conversation $conversation) => $user->can('assign', $conversation));

        foreach ($authorized as $conversation) {
            $conversation->update(['assigned_to' => $validated['user_id']]);
            $conversation->users()->syncWithoutDetaching([
                $validated['user_id'] => ['assigned_by' => $user->id],
            ]);
        }

        return response()->json([
            'updated' => $authorized->count(),
            'failed' => count($validated['ids']) - $authorized->count(),
            'assigned_to' => $validated['user_id'],
        ]);
    }

    public function bulkArchive(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'archived' => ['required', 'boolean'],
        ]);

        $conversations = Conversation::query()->whereIn('id', $validated['ids'])->get();

        $authorized = $conversations->filter(fn (Conversation $conversation) => $user->can('update', $conversation));

        foreach ($authorized as $conversation) {
            if ($validated['archived']) {
                $conversation->archive();
            } else {
                $conversation->unarchive();
            }
        }

        return response()->json([
            'updated' => $authorized->count(),
            'failed' => count($validated['ids']) - $authorized->count(),
            'archived' => $validated['archived'],
        ]);
    }

    /**
     * Activa o desactiva la auto-respuesta de IA para múltiples conversaciones.
     */
    public function bulkAiAutoreply(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $conversations = Conversation::query()->whereIn('id', $validated['ids'])->get();

        $authorized = $conversations->filter(fn (Conversation $conversation) => $user->can('update', $conversation));

        foreach ($authorized as $conversation) {
            $conversation->update(['ai_autoreply_enabled' => $validated['enabled']]);
            if ($validated['enabled']) {
                $this->cancelManualAiDrafts($conversation);
            }
        }

        return response()->json([
            'updated' => $authorized->count(),
            'failed' => count($validated['ids']) - $authorized->count(),
            'enabled' => $validated['enabled'],
        ]);
    }

    private function cancelManualAiDrafts(Conversation $conversation): void
    {
        $drafts = ManualAiDraft::where('conversation_id', $conversation->id)
            ->whereIn('status', ['pending', 'ready'])
            ->get();

        foreach ($drafts as $draft) {
            $draft->update(['status' => 'cancelled']);
            broadcast(new ManualAiDraftUpdated($draft->fresh()));
        }
    }

    /**
     * Difusión: envía una plantilla de WhatsApp aprobada a varias conversaciones.
     * Despacha un job por conversación con delay escalonado (~20 msg/s) para
     * no exceder el throughput de Meta. Responde de inmediato con el conteo encolado.
     */
    public function bulkBroadcast(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'template_id' => ['required', 'integer', 'exists:whatsapp_templates,id'],
            'components' => ['nullable', 'array'],
        ]);

        $conversations = Conversation::query()
            ->whereIn('id', $validated['ids'])
            // Las difusiones son 1:1 por contacto; un grupo de WhatsApp no
            // tiene sentido acá (8 personas, no miles) y Meta ni siquiera
            // reporta métricas de plantilla para mensajes de grupo.
            ->where('kind', 'direct')
            ->with('channel.whatsappConfig')
            ->get();

        $authorized = $conversations->filter(fn (Conversation $conversation) => $user->can('sendMessage', $conversation));

        if ($authorized->isEmpty()) {
            return response()->json([
                'message' => 'No hay conversaciones autorizadas para enviar la difusión.',
            ], 422);
        }

        // Una difusión opera sobre UN solo número de WhatsApp: las plantillas
        // son por canal. El front ya lo garantiza; esto es defensa server-side.
        $configIds = $authorized
            ->map(fn (Conversation $conversation) => $conversation->channel?->whatsappConfig?->id)
            ->unique();

        if ($configIds->count() !== 1 || $configIds->first() === null) {
            return response()->json([
                'message' => 'Todas las conversaciones deben pertenecer al mismo canal de WhatsApp.',
            ], 422);
        }

        $template = WhatsAppTemplate::query()->findOrFail($validated['template_id']);

        if (! $template->status->isApproved() || $template->whatsapp_config_id !== $configIds->first()) {
            return response()->json([
                'message' => 'La plantilla debe estar aprobada y pertenecer al canal seleccionado.',
            ], 422);
        }

        $components = $validated['components'] ?? [];

        foreach ($authorized->values() as $index => $conversation) {
            SendBroadcastMessageJob::dispatch(
                $conversation->id,
                $template->id,
                $components,
                $user->id,
                $user->tenant_id,
            )->delay(now()->addMilliseconds($index * 50));
        }

        return response()->json([
            'queued' => $authorized->count(),
            'failed' => count($validated['ids']) - $authorized->count(),
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
        ]);

        $conversations = Conversation::query()->whereIn('id', $validated['ids'])->get();

        $authorized = $conversations->filter(fn (Conversation $conversation) => $user->can('delete', $conversation));

        $deleted = 0;
        foreach ($authorized as $conversation) {
            $conversation->delete();
            $deleted++;
        }

        return response()->json([
            'deleted' => $deleted,
            'failed' => count($validated['ids']) - $deleted,
        ]);
    }

    /**
     * Máximo de conversaciones a sincronizar como "visto" en Meta por request de bulk.
     * El throughput de Cloud API (80 mps por número, inbound + outbound) es compartido
     * con los mensajes reales, así que se acota para no consumir esa cuota.
     */
    private const BULK_READ_META_SYNC_CAP = 20;

    /**
     * Ventana en la que Meta acepta marcar un mensaje entrante como leído.
     * Fuera de ella la API responde "(#100) Message ID does not exist".
     */
    private const META_READ_WINDOW_HOURS = 24;

    public function bulkMarkRead(Request $request, WhatsAppMessageService $whatsappService): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
            'read' => ['required', 'boolean'],
        ]);

        $conversations = Conversation::query()
            ->with('channel')
            ->whereIn('id', $validated['ids'])
            ->get();

        $authorized = $conversations->filter(fn (Conversation $conversation) => $user->can('view', $conversation));

        if ($validated['read']) {
            $this->bulkMarkConversationsRead($authorized, $whatsappService);
        } else {
            $this->bulkMarkConversationsUnread($authorized);
        }

        return response()->json([
            'updated' => $authorized->count(),
            'failed' => count($validated['ids']) - $authorized->count(),
            'read' => $validated['read'],
        ]);
    }

    /**
     * Marca como leídas en bloque. Sincroniza el estado "visto" con Meta solo para
     * un subconjunto acotado de conversaciones recientes, para evitar errores por
     * message IDs expirados y no agotar el throughput de la Cloud API.
     *
     * @param  Collection<int, Conversation>  $conversations
     */
    private function bulkMarkConversationsRead(Collection $conversations, WhatsAppMessageService $whatsappService): void
    {
        $conversationIds = $conversations->pluck('id');

        if ($conversationIds->isEmpty()) {
            return;
        }

        $conversationsWithUnread = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('direction', MessageDirection::INBOUND)
            ->whereNull('read_at')
            ->distinct()
            ->pluck('conversation_id');

        $manuallyUnreadIds = $conversations
            ->filter(fn (Conversation $conversation) => $conversation->manual_unread)
            ->pluck('id');

        if ($conversationsWithUnread->isEmpty() && $manuallyUnreadIds->isEmpty()) {
            return;
        }

        $metaSyncTargets = $this->resolveRecentMetaReadTargets($conversationsWithUnread);

        if ($conversationsWithUnread->isNotEmpty()) {
            Message::query()
                ->whereIn('conversation_id', $conversationsWithUnread)
                ->where('direction', MessageDirection::INBOUND)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        if ($manuallyUnreadIds->isNotEmpty()) {
            Conversation::query()
                ->whereIn('id', $manuallyUnreadIds)
                ->update(['manual_unread' => false]);
        }

        foreach ($conversations as $conversation) {
            $externalId = $metaSyncTargets->get($conversation->id);
            if ($externalId) {
                $whatsappService->markIncomingAsReadOnMeta($conversation, $externalId);
            }
        }
    }

    /**
     * Resuelve hasta BULK_READ_META_SYNC_CAP conversaciones cuyo último mensaje
     * entrante sin leer está dentro de la ventana aceptada por Meta, devolviendo
     * su external_id para sincronizar el "visto".
     *
     * @param  Collection<int, int>  $conversationIds
     * @return Collection<int, string> conversation_id => external_id
     */
    private function resolveRecentMetaReadTargets(Collection $conversationIds): Collection
    {
        return Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('direction', MessageDirection::INBOUND)
            ->whereNull('read_at')
            ->whereNotNull('external_id')
            ->where('created_at', '>=', now()->subHours(self::META_READ_WINDOW_HOURS))
            ->orderBy('created_at')
            ->get(['conversation_id', 'external_id'])
            ->groupBy('conversation_id')
            ->map(fn ($messages) => $messages->last()->external_id)
            ->take(self::BULK_READ_META_SYNC_CAP);
    }

    /**
     * Marca como no leídas en bloque mediante el flag manual_unread, que funciona
     * en cualquier conversación (incluso sin mensajes entrantes).
     *
     * @param  Collection<int, Conversation>  $conversations
     */
    private function bulkMarkConversationsUnread(Collection $conversations): void
    {
        $conversationIds = $conversations->pluck('id');

        if ($conversationIds->isEmpty()) {
            return;
        }

        Conversation::query()
            ->whereIn('id', $conversationIds)
            ->update(['manual_unread' => true]);
    }

    private function parseTagSlugs(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }
}
