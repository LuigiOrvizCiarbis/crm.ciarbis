<?php

namespace App\Jobs;

use App\Enums\AiBookingStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\GoogleCalendarConnection;
use App\Models\Scopes\TenantScope;
use App\Models\Task;
use App\Models\TaskCalendarSync;
use App\Support\GoogleCalendarClient;
use Google\Service\Calendar as GoogleCalendarService;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventExtendedProperties;
use Google\Service\Calendar\EventReminder;
use Google\Service\Calendar\EventReminders;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inserta, actualiza o cancela el evento de Google Calendar de una tarea en el
 * calendario de su responsable. Las reuniones son eventos con invitado y Meet;
 * el resto de los tipos son recordatorios privados en su `deadline`, que no
 * ocupan la agenda (la disponibilidad de la IA ignora los eventos
 * `transparent`). Idempotente: usa un ID determinístico por
 * tenant+tarea+generación, y ante un 409 (evento cancelado que Google no
 * permite reinsertar — ver
 * https://developers.google.com/calendar/api/guides/errors) reintenta con
 * `update` en vez de `insert`. Por eso cambiar el tipo de una tarea reescribe
 * el mismo evento en lugar de crear otro.
 *
 * "Generación": al reasignar una tarea, el evento del responsable anterior se
 * cancela y se crea uno nuevo para el nuevo responsable. Si la tarea vuelve a
 * reasignarse al responsable original, el ID determinístico de la generación
 * previa ya está cancelado en Google (no se puede reusar), por eso cada
 * reasignación incrementa `event_generation` y el ID lo incorpora.
 */
class SyncTaskCalendarEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** Duración fija del evento de un recordatorio (tareas que no son reunión). */
    public const REMINDER_MINUTES = 30;

    public function backoff(): int
    {
        $schedule = [30, 60, 300, 900];
        $index = max(0, min($this->attempts() - 1, count($schedule) - 1));

        return $schedule[$index] + random_int(0, 10);
    }

    public function __construct(
        public int $taskId,
        public string $action, // 'upsert' | 'cancel' | 'cancel_previous' | 'refresh_conference'
        public ?int $cancelOwnerUserId = null,
        public ?string $cancelCalendarId = null,
        public ?string $cancelEventId = null,
        public int $conferencePoll = 0,
        public ?int $cancelTenantId = null,
    ) {}

    /**
     * Cancela un evento puntual (snapshot de antes de la reasignación) sin
     * tocar el `task_calendar_syncs` actual, que ya representa la nueva
     * generación creada para el nuevo responsable.
     */
    public static function dispatchCancelExisting(int $taskId, int $tenantId, int $ownerUserId, string $calendarId, string $eventId): void
    {
        self::dispatch($taskId, 'cancel_previous', $ownerUserId, $calendarId, $eventId, cancelTenantId: $tenantId);
    }

    /**
     * Encola las tareas del usuario que todavía no llegaron a su Google
     * Calendar en ese workspace: sin fila de sync, con un intento pendiente o
     * fallido, o pausadas (p. ej. una reunión que se pasó a seguimiento antes
     * de que existieran los recordatorios). Las reuniones van todas; los
     * recordatorios solo si siguen abiertos y vencen a futuro, para no llenar
     * el calendario de pasado. Todo lo que entra tiene horario, así que una
     * fila pausada vuelve a publicarse en vez de repetir el borrado.
     *
     * @return int tareas encoladas
     */
    public static function dispatchPendingFor(int $tenantId, int $userId): int
    {
        $taskIds = Task::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('assigned_to', $userId)
            ->where('status', '!=', TaskStatus::CANCELED)
            ->where(fn ($query) => $query
                ->where('type', TaskType::MEETING)
                ->orWhere(fn ($reminder) => $reminder
                    ->where('deadline', '>=', now())
                    ->where('status', '!=', TaskStatus::DONE)))
            ->pluck('id');

        if ($taskIds->isEmpty()) {
            return 0;
        }

        $syncs = TaskCalendarSync::withoutGlobalScope(TenantScope::class)
            ->whereIn('task_id', $taskIds)
            ->get(['task_id', 'status']);

        $pending = $taskIds
            ->diff($syncs->pluck('task_id'))
            ->merge($syncs->whereIn('status', ['pending', 'error', 'paused'])->pluck('task_id'))
            ->unique();

        $pending->each(fn (int $taskId) => self::dispatch($taskId, 'upsert'));

        return $pending->count();
    }

    public function handle(): void
    {
        // `cancel_previous` se resuelve con el snapshot del constructor: corre
        // igual si la tarea fue eliminada, que es justamente cuando hay que
        // borrar el evento que quedó en Google.
        if ($this->action === 'cancel_previous') {
            $this->cancelPrevious();

            return;
        }

        $task = Task::withoutGlobalScope(TenantScope::class)->find($this->taskId);

        if (! $task) {
            return;
        }

        // Un upsert encolado antes de cancelar/rechazar el turno no debe
        // publicarlo después. Las acciones de cancelación siguen operativas.
        if ($this->action === 'upsert' && (
            $task->status === TaskStatus::CANCELED
            || in_array($task->ai_booking_status, [AiBookingStatus::PENDING, AiBookingStatus::REJECTED], true)
        )) {
            return;
        }

        if ($this->action === 'refresh_conference') {
            if ($task->type === TaskType::MEETING) {
                $this->refreshConference($task);
            }

            return;
        }

        $sync = TaskCalendarSync::withoutGlobalScope(TenantScope::class)
            ->firstOrNew(['task_id' => $task->id]);

        if ($this->action === 'cancel') {
            $this->cancel($task, $sync);

            return;
        }

        $this->upsert($task, $sync);
    }

    /**
     * Se agotaron los reintentos: sin esto el sync queda en `synced` mientras
     * el evento en Google puede haber quedado sin borrar/actualizar, un estado
     * ambiguo que nadie ve. `cancel_previous` se saltea: opera sobre un
     * snapshot y no tiene fila de sync propia que marcar.
     */
    public function failed(?\Throwable $e): void
    {
        if ($this->action === 'cancel_previous') {
            return;
        }

        $sync = TaskCalendarSync::withoutGlobalScope(TenantScope::class)
            ->where('task_id', $this->taskId)
            ->first();

        if (! $sync) {
            return;
        }

        $this->markError($sync, $e?->getMessage() ?? 'job failed');
    }

    private function cancelPrevious(): void
    {
        $connection = $this->connectionFor($this->cancelTenantId, $this->cancelOwnerUserId);

        if (! $connection) {
            return;
        }

        $service = GoogleCalendarClient::serviceFor($connection);

        if (! $service) {
            return;
        }

        try {
            $service->events->delete($this->cancelCalendarId, $this->cancelEventId, [
                'sendUpdates' => 'all',
            ]);
        } catch (GoogleServiceException $e) {
            if ($this->isRetryable($e)) {
                throw $e;
            }

            if (! in_array($e->getCode(), [404, 410], true)) {
                Log::warning('SyncTaskCalendarEventJob: cancelPrevious failed', [
                    'task_id' => $this->taskId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 429 y 5xx son transitorios del lado de Google: hay que dejar burbujear la
     * excepción para que la cola reintente con `$backoff` en vez de dar el
     * borrado por hecho y abandonar el evento en el calendario.
     */
    private function isRetryable(GoogleServiceException $e): bool
    {
        if ($e->getCode() === 429 || $e->getCode() >= 500) {
            return true;
        }

        if ($e->getCode() !== 403) {
            return false;
        }

        $reasons = collect($e->getErrors())->pluck('reason');

        return $reasons->intersect([
            'rateLimitExceeded',
            'userRateLimitExceeded',
            'quotaExceeded',
        ])->isNotEmpty();
    }

    private function upsert(Task $task, TaskCalendarSync $sync): void
    {
        // Sin responsable u horario no hay dónde publicarla; si ya tenía un
        // evento (p. ej. se le quitó el vencimiento), se retira.
        if (! $task->hasCalendarSlot()) {
            $this->cancel($task, $sync);

            return;
        }

        $connection = $this->connectionFor($task->tenant_id, $task->assigned_to);

        // Los recordatorios son un extra de quien conectó Google: sin conexión
        // no se crea la fila de sync, así los workspaces que no usan Google
        // Calendar no ven cada tarea como "pendiente de sincronizar". Las
        // reuniones sí la crean, porque ese aviso le sirve a quien las agenda.
        if (! $connection && $task->type !== TaskType::MEETING && ! $sync->exists) {
            return;
        }

        // Estos campos son NOT NULL en la tabla: se completan siempre, incluso
        // en los early-returns de abajo que dejan el sync en pending/error.
        $sync->tenant_id = $task->tenant_id;
        $sync->owner_user_id = $task->assigned_to;
        $sync->google_calendar_id = $sync->google_calendar_id ?: 'primary';
        $sync->event_generation = $sync->event_generation ?: 1;

        if (! $sync->external_event_id) {
            $sync->external_event_id = $this->deterministicEventId($task, $sync->event_generation);
        }

        if (! $connection || $connection->status === 'needs_reauth') {
            $this->markPending($sync, $connection ? 'needs_reauth' : 'not_connected');

            return;
        }

        $service = GoogleCalendarClient::serviceFor($connection);

        if (! $service) {
            $this->markPending($sync, 'needs_reauth');

            return;
        }

        $event = $this->buildEvent($task, $sync);

        try {
            $created = $service->events->insert($sync->google_calendar_id, $event, [
                'sendUpdates' => 'all',
                'conferenceDataVersion' => 1,
            ]);

            $this->markSynced($sync, $created, $task->type === TaskType::MEETING);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 409) {
                $this->retryAsUpdate($service, $task, $sync, $event);

                return;
            }

            if ($e->getCode() === 403 && str_contains($e->getMessage(), 'ACCESS_TOKEN_SCOPE_INSUFFICIENT')) {
                GoogleCalendarClient::markNeedsReauth($connection, 'insufficient_calendar_scope');
                $this->markPending($sync, 'needs_reauth');

                return;
            }

            if ($this->isRetryable($e)) {
                $this->markPending($sync, 'retrying');

                throw $e;
            }

            $this->markError($sync, $e->getMessage());
        } catch (TransferException $e) {
            $this->markPending($sync, 'retrying');

            throw $e;
        } catch (\Exception $e) {
            $this->markError($sync, $e->getMessage());
        }
    }

    private function retryAsUpdate(GoogleCalendarService $service, Task $task, TaskCalendarSync $sync, Event $event): void
    {
        try {
            $calendarId = $sync->google_calendar_id ?: 'primary';
            // Reuse the current conference data on updates. Google requires a
            // new requestId for every new conference creation request. A
            // reminder drops it: a meeting turned into a follow-up has no call.
            $existing = $service->events->get($calendarId, $sync->external_event_id);
            if ($task->type === TaskType::MEETING && $existing->getConferenceData()) {
                $event->setConferenceData($existing->getConferenceData());
            }

            $event->setStatus('confirmed');
            $updated = $service->events->update($calendarId, $sync->external_event_id, $event, [
                'sendUpdates' => 'all',
                'conferenceDataVersion' => 1,
            ]);

            $this->markSynced($sync, $updated, $task->type === TaskType::MEETING);
        } catch (GoogleServiceException $e) {
            if ($this->isRetryable($e)) {
                $this->markPending($sync, 'retrying');

                throw $e;
            }

            $this->markError($sync, $e->getMessage());
        } catch (TransferException $e) {
            $this->markPending($sync, 'retrying');

            throw $e;
        } catch (\Exception $e) {
            $this->markError($sync, $e->getMessage());
        }
    }

    private function refreshConference(Task $task): void
    {
        $sync = TaskCalendarSync::withoutGlobalScope(TenantScope::class)
            ->where('task_id', $task->id)
            ->first();

        if (! $sync || ! $sync->external_event_id) {
            return;
        }

        $connection = $this->connectionFor($task->tenant_id, $task->assigned_to);
        $service = $connection ? GoogleCalendarClient::serviceFor($connection) : null;

        if (! $service) {
            $this->markPending($sync, 'needs_reauth');

            return;
        }

        try {
            $event = $service->events->get($sync->google_calendar_id ?: 'primary', $sync->external_event_id);
            $meetLink = $this->extractMeetLink($event);

            if ($meetLink) {
                $this->markSynced($sync, $event, true);

                return;
            }

            $status = $event->getConferenceData()?->getCreateRequest()?->getStatus()?->getStatusCode();
            if ($status === 'failure') {
                $this->markError($sync, 'conference_creation_failed');

                return;
            }

            if ($this->conferencePoll < 4) {
                $this->markPending($sync, 'conference_pending');
                self::dispatch($task->id, 'refresh_conference', null, null, null, $this->conferencePoll + 1)
                    ->delay(now()->addSeconds(10));

                return;
            }

            $this->markError($sync, 'conference_creation_timeout');
        } catch (GoogleServiceException $e) {
            if ($this->isRetryable($e)) {
                $this->markPending($sync, 'retrying');

                throw $e;
            }

            $this->markError($sync, $e->getMessage());
        } catch (TransferException $e) {
            $this->markPending($sync, 'retrying');

            throw $e;
        }
    }

    /**
     * La conexión es por usuario *y* workspace. `$user->googleCalendarConnection`
     * no sirve acá: en el worker no hay usuario autenticado, así que el
     * TenantScope no filtra y un usuario que pertenece a varios workspaces
     * puede resolver la conexión de otro tenant (otra cuenta de Google o una
     * conexión en `needs_reauth`), dejando la reunión sin llegar a su calendario.
     */
    private function connectionFor(?int $tenantId, ?int $userId): ?GoogleCalendarConnection
    {
        if (! $tenantId || ! $userId) {
            return null;
        }

        return GoogleCalendarConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();
    }

    private function cancel(Task $task, TaskCalendarSync $sync): void
    {
        // Pausada = el evento ya se retiró: cada edición de una tarea sin
        // horario vuelve a pasar por acá y no tiene que repetir el DELETE.
        if (! $sync->exists || $sync->status === 'paused') {
            return;
        }

        if ($sync->external_event_id && ! $this->deleteEvent($task, $sync)) {
            return;
        }

        $sync->status = 'paused';
        $sync->synced_at = now();
        $sync->save();
    }

    /** False si no hay conexión utilizable para borrarlo: el sync queda como estaba. */
    private function deleteEvent(Task $task, TaskCalendarSync $sync): bool
    {
        $connection = $this->connectionFor($sync->tenant_id, $sync->owner_user_id);
        $service = $connection ? GoogleCalendarClient::serviceFor($connection) : null;

        if (! $service) {
            return false;
        }

        try {
            $service->events->delete($sync->google_calendar_id, $sync->external_event_id, [
                'sendUpdates' => 'all',
            ]);
        } catch (GoogleServiceException $e) {
            if ($this->isRetryable($e)) {
                throw $e;
            }

            // 404/410: ya no existe en Google, no es un error para nosotros.
            if (! in_array($e->getCode(), [404, 410], true)) {
                Log::warning('SyncTaskCalendarEventJob: cancel failed', [
                    'task_id' => $task->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    private function buildEvent(Task $task, TaskCalendarSync $sync): Event
    {
        $event = $task->type === TaskType::MEETING
            ? $this->meetingEvent($task)
            : $this->reminderEvent($task);

        $event->setId($sync->external_event_id);

        if ($task->recurrence) {
            $recurrence = str_starts_with($task->recurrence, 'RRULE:')
                ? $task->recurrence
                : 'RRULE:'.$task->recurrence;
            $event->setRecurrence([$recurrence]);
        }

        $event->setExtendedProperties(new EventExtendedProperties([
            'private' => [
                'crm_task_id' => (string) $task->id,
                'crm_tenant_id' => (string) $task->tenant_id,
            ],
        ]));

        return $event;
    }

    private function meetingEvent(Task $task): Event
    {
        $timezone = $task->meeting_timezone ?: 'America/Argentina/Buenos_Aires';

        $event = new Event([
            'summary' => $task->name,
            'description' => $task->description,
            'start' => new EventDateTime([
                'dateTime' => $task->starts_at->toRfc3339String(),
                'timeZone' => $timezone,
            ]),
            'end' => new EventDateTime([
                'dateTime' => $task->ends_at->toRfc3339String(),
                'timeZone' => $timezone,
            ]),
        ]);

        $guestEmail = $task->meeting_guest_email ?: $task->contact?->email;

        if ($guestEmail) {
            $event->setAttendees([new EventAttendee(['email' => $guestEmail])]);
        }

        $event->setConferenceData(new ConferenceData([
            'createRequest' => new CreateConferenceRequest([
                'requestId' => Str::uuid()->toString(),
                'conferenceSolutionKey' => new ConferenceSolutionKey(['type' => 'hangoutsMeet']),
            ]),
        ]));

        return $event;
    }

    /**
     * Recordatorio privado del responsable: sin invitados (el contacto no debe
     * recibir nada por un seguimiento interno), sin Meet y `transparent` para
     * no bloquear los turnos que ofrece la IA.
     */
    private function reminderEvent(Task $task): Event
    {
        $timezone = (string) config('app.timezone');

        return new Event([
            'summary' => "[{$task->type->label()}] {$task->name}",
            'description' => $task->description,
            'start' => new EventDateTime([
                'dateTime' => $task->deadline->toRfc3339String(),
                'timeZone' => $timezone,
            ]),
            'end' => new EventDateTime([
                'dateTime' => $task->deadline->copy()->addMinutes(self::REMINDER_MINUTES)->toRfc3339String(),
                'timeZone' => $timezone,
            ]),
            'transparency' => 'transparent',
            // Quien tenga acceso al calendario compartido no ve el nombre de la
            // tarea ni los datos del contacto que pueda tener la descripción.
            'visibility' => 'private',
            'reminders' => new EventReminders([
                'useDefault' => false,
                'overrides' => [new EventReminder(['method' => 'popup', 'minutes' => 10])],
            ]),
        ]);
    }

    /**
     * ID válido para Google Calendar: charset base32hex (a-v, 0-9), 5-1024
     * chars. https://developers.google.com/workspace/calendar/api/v3/reference/events/insert
     * sha1 hexadecimal (0-9a-f) es un subconjunto directo de ese charset, así
     * que no hace falta remapear símbolos.
     */
    private function deterministicEventId(Task $task, int $generation): string
    {
        $hex = hash('sha1', "crm-task-{$task->tenant_id}-{$task->id}-gen{$generation}");

        return 'crm'.substr($hex, 0, 26);
    }

    private function markSynced(TaskCalendarSync $sync, Event $event, bool $expectsConference): void
    {
        $sync->html_link = $event->getHtmlLink();
        $sync->meet_link = $this->extractMeetLink($event);
        $sync->status = ! $expectsConference || $sync->meet_link ? 'synced' : 'pending';
        $sync->last_error = $sync->status === 'pending' ? 'conference_pending' : null;
        $sync->synced_at = now();
        $sync->save();

        if ($sync->status === 'pending' && $this->conferencePoll === 0) {
            self::dispatch($sync->task_id, 'refresh_conference')->delay(now()->addSeconds(10));
        }
    }

    private function markPending(TaskCalendarSync $sync, string $reason): void
    {
        $sync->status = 'pending';
        $sync->last_error = $reason;
        $sync->save();
    }

    private function markError(TaskCalendarSync $sync, string $message): void
    {
        Log::error('SyncTaskCalendarEventJob: sync failed', [
            'task_id' => $sync->task_id,
            'error' => $message,
        ]);

        $sync->status = 'error';
        $sync->last_error = $message;
        $sync->save();
    }

    private function extractMeetLink(Event $event): ?string
    {
        $entryPoints = $event->getConferenceData()?->getEntryPoints() ?? [];

        foreach ($entryPoints as $entryPoint) {
            if ($entryPoint->getEntryPointType() === 'video') {
                return $entryPoint->getUri();
            }
        }

        return null;
    }
}
