<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\Task;
use App\Models\TaskCalendarSync;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\JsonResponse;

class TaskController extends Controller
{
    private const TIMEZONE_ALIASES = [
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
    ];

    private const EAGER_LOAD = [
        'assignedUser:id,name,email',
        'contact:id,name,phone,email,source',
        'conversation:id,contact_id,channel_id,last_message_content,last_message_at',
        'conversation.contact:id,name',
        'conversation.channel:id,name,type',
        'opportunity:id,title,status,value',
        'calendarSync',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        $user = $request->user();

        $query = Task::query()
            ->with(self::EAGER_LOAD)
            ->visibleTo($user)
            ->orderByRaw('deadline IS NULL')
            ->orderBy('deadline')
            ->orderByDesc('updated_at');

        $this->applyFilters($query, $request);

        $tasks = $query->paginate((int) $request->query('per_page', 100));

        return response()->json([
            'data' => $tasks->items(),
            'meta' => [
                'total' => $tasks->total(),
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Task::class);
        $this->normalizeMeetingTimezone($request);
        $validated = $request->validate($this->rules($request));

        $task = Task::create($this->payload($validated));

        if ($task->type === TaskType::MEETING && $task->status !== TaskStatus::CANCELED) {
            DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatch($task->id, 'upsert'));
        }

        $task->load(self::EAGER_LOAD);

        return response()->json(['data' => $task], 201);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json(['data' => $task->load(self::EAGER_LOAD)]);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);
        $this->normalizeMeetingTimezone($request);

        $validated = $request->validate($this->rules($request, $task));

        $wasMeeting = $task->type === TaskType::MEETING;
        $previousAssignee = $task->assigned_to;
        $previousStatus = $task->status;

        $task->fill($this->payload($validated, true));

        if (($validated['status'] ?? null) === TaskStatus::DONE->value && ! $task->completed_at) {
            $task->completed_at = now();
        }

        if (array_key_exists('status', $validated) && $validated['status'] !== TaskStatus::DONE->value) {
            $task->completed_at = $validated['completed_at'] ?? null;
        }

        $reassigned = $wasMeeting && array_key_exists('assigned_to', $validated) && $validated['assigned_to'] !== $previousAssignee;

        // Si deja de ser reunión, el job ya no puede cancelar por sí solo (su
        // guard de tipo la descarta), así que el evento se captura antes.
        $droppedMeeting = $wasMeeting && $task->type !== TaskType::MEETING;
        $snapshot = $droppedMeeting ? $this->cancelableSnapshot($task) : null;

        $task->save();

        $this->syncCalendarAfterUpdate($task, $wasMeeting, $previousStatus, $reassigned, $snapshot);

        $task->load(self::EAGER_LOAD);

        return response()->json(['data' => $task]);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->authorize('delete', $task);

        $isMeeting = $task->type === TaskType::MEETING;
        $taskId = $task->id;

        // El sync cae por cascade junto con la tarea, así que el snapshot del
        // evento tiene que tomarse antes del delete: después no hay de dónde
        // leerlo y el evento quedaría huérfano en el calendario.
        $snapshot = $isMeeting ? $this->cancelableSnapshot($task) : null;

        $task->delete();

        if ($snapshot) {
            DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatchCancelExisting(
                $taskId,
                $snapshot['owner_user_id'],
                $snapshot['google_calendar_id'],
                $snapshot['external_event_id'],
            ));
        }

        return response()->json(['message' => 'Tarea eliminada']);
    }

    public function retryCalendarSync(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);

        if ($task->type !== TaskType::MEETING) {
            return response()->json(['message' => 'La tarea no es una reunión'], 422);
        }

        DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatch($task->id, 'upsert'));

        return response()->json(['message' => 'Reintento encolado']);
    }

    /**
     * Reasignar cancela el evento del responsable anterior (calendario ajeno
     * al nuevo dueño) e incrementa `event_generation` para no reusar un ID de
     * evento cancelado en el nuevo insert (Google rechaza esa reinserción con
     * 409). Cancelado/eliminación cancela el evento; "hecho" lo conserva.
     */
    private function syncCalendarAfterUpdate(Task $task, bool $wasMeeting, TaskStatus $previousStatus, bool $reassigned, ?array $droppedMeetingSnapshot = null): void
    {
        $isMeetingNow = $task->type === TaskType::MEETING;

        if (! $wasMeeting && ! $isMeetingNow) {
            return;
        }

        if ($isMeetingNow && $task->status === TaskStatus::CANCELED && $previousStatus !== TaskStatus::CANCELED) {
            DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatch($task->id, 'cancel'));

            return;
        }

        if (! $isMeetingNow) {
            if ($droppedMeetingSnapshot) {
                DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatchCancelExisting(
                    $task->id,
                    $droppedMeetingSnapshot['owner_user_id'],
                    $droppedMeetingSnapshot['google_calendar_id'],
                    $droppedMeetingSnapshot['external_event_id'],
                ));

                TaskCalendarSync::where('task_id', $task->id)->update([
                    'status' => 'paused',
                    'synced_at' => now(),
                ]);
            }

            return;
        }

        if ($task->status === TaskStatus::CANCELED) {
            return;
        }

        if ($reassigned) {
            $sync = TaskCalendarSync::where('task_id', $task->id)->first();

            if ($sync && $sync->status !== 'paused' && $sync->external_event_id) {
                $previousEventId = $sync->external_event_id;
                $previousCalendarId = $sync->google_calendar_id;
                $previousOwnerId = $sync->owner_user_id;

                $sync->increment('event_generation');
                $sync->update(['external_event_id' => '', 'status' => 'pending']);

                DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatchCancelExisting(
                    $task->id,
                    $previousOwnerId,
                    $previousCalendarId,
                    $previousEventId,
                ));
            }
        }

        DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatch($task->id, 'upsert'));
    }

    /**
     * Datos mínimos para borrar el evento en Google sin depender de la tarea ni
     * del `task_calendar_syncs`, que pueden dejar de existir o de aplicar.
     *
     * @return array{owner_user_id: int, google_calendar_id: string, external_event_id: string}|null
     */
    private function cancelableSnapshot(Task $task): ?array
    {
        $sync = TaskCalendarSync::where('task_id', $task->id)->first();

        if (! $sync || $sync->status === 'paused' || ! $sync->external_event_id) {
            return null;
        }

        return [
            'owner_user_id' => $sync->owner_user_id,
            'google_calendar_id' => $sync->google_calendar_id,
            'external_event_id' => $sync->external_event_id,
        ];
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        foreach (['status', 'priority', 'type'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->string($field));
            }
        }

        if ($request->filled('assigned_to')) {
            $request->query('assigned_to') === 'none'
                ? $query->whereNull('assigned_to')
                : $query->where('assigned_to', $request->integer('assigned_to'));
        }

        if ($request->filled('contact_id')) {
            $query->where('contact_id', $request->integer('contact_id'));
        }

        if ($request->filled('conversation_id')) {
            $query->where('conversation_id', $request->integer('conversation_id'));
        }

        if ($request->filled('opportunity_id')) {
            $query->where('opportunity_id', $request->integer('opportunity_id'));
        }
    }

    private function rules(Request $request, ?Task $task = null): array
    {
        $tenantId = $request->user()->tenant_id;
        $required = $task ? ['sometimes'] : ['required'];

        $effectiveType = $request->input('type', $task?->type?->value);
        $isMeeting = $effectiveType === TaskType::MEETING->value;
        $wasMeeting = $task?->type === TaskType::MEETING;

        // En una tarea que ya era reunión los valores están guardados, así que
        // un PATCH parcial que no los reenvía los conserva (`sometimes`). Al
        // convertir una tarea a reunión no hay nada que conservar: cada campo
        // que la tarea todavía no tenga hay que exigirlo, o se guarda una
        // reunión incompleta y el job de sync no crea el evento.
        $meetingRule = function (string $field) use ($isMeeting, $task, $wasMeeting): array {
            if (! $isMeeting) {
                return ['nullable'];
            }

            if (! $task) {
                return ['required'];
            }

            return $wasMeeting || filled($task->{$field}) ? ['sometimes'] : ['required'];
        };

        return [
            'name' => [...$required, 'string', 'max:255'],
            'status' => [$task ? 'sometimes' : 'nullable', Rule::enum(TaskStatus::class)],
            'priority' => [$task ? 'sometimes' : 'nullable', Rule::enum(TaskPriority::class)],
            'type' => [$task ? 'sometimes' : 'nullable', Rule::enum(TaskType::class)],
            'deadline' => ['nullable', 'date'],
            'description' => ['nullable', 'string'],
            'assigned_to' => [...$meetingRule('assigned_to'), 'integer', Rule::exists('users', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'conversation_id' => ['nullable', 'integer', Rule::exists('conversations', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'opportunity_id' => ['nullable', 'integer', Rule::exists('opportunities', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'reminders' => ['nullable', 'array'],
            'recurrence' => ['nullable', 'string', 'max:255'],
            'depends_on' => ['nullable', 'array'],
            'checklist' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array'],
            'synced_calendars' => ['nullable', 'array'],
            'completed_at' => ['nullable', 'date'],
            'starts_at' => [...$meetingRule('starts_at'), 'date'],
            'ends_at' => [...$meetingRule('ends_at'), 'date', 'after:starts_at'],
            'meeting_timezone' => [...$meetingRule('meeting_timezone'), 'timezone'],
            'meeting_guest_email' => ['nullable', 'email'],
        ];
    }

    private function normalizeMeetingTimezone(Request $request): void
    {
        if (! $request->filled('meeting_timezone')) {
            return;
        }

        $timezone = $request->string('meeting_timezone')->toString();
        $request->merge([
            'meeting_timezone' => self::TIMEZONE_ALIASES[$timezone] ?? $timezone,
        ]);
    }

    private function payload(array $validated, bool $partial = false): array
    {
        $defaults = $partial ? [] : [
            'status' => TaskStatus::NEW->value,
            'priority' => TaskPriority::MEDIUM->value,
            'type' => TaskType::FOLLOW_UP->value,
            'reminders' => [],
            'depends_on' => [],
            'checklist' => [],
            'attachments' => [],
            'synced_calendars' => [],
        ];

        $payload = array_merge($defaults, $validated);

        if (($payload['status'] ?? null) === TaskStatus::DONE->value && ! array_key_exists('completed_at', $payload)) {
            $payload['completed_at'] = now();
        }

        // Las reuniones fijan deadline = starts_at para no romper las vistas
        // de tareas (ordenamiento, calendario) que ya dependen de `deadline`.
        if (array_key_exists('starts_at', $payload) && $payload['starts_at']) {
            $payload['deadline'] = $payload['starts_at'];
        }

        return $payload;
    }
}
