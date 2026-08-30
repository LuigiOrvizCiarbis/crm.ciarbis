<?php

namespace App\Jobs;

use App\Models\Scopes\TenantScope;
use App\Models\Task;
use App\Models\TaskCalendarSync;
use App\Models\User;
use App\Support\GoogleCalendarClient;
use Google\Service\Calendar as GoogleCalendarService;
use Google\Service\Calendar\ConferenceData;
use Google\Service\Calendar\ConferenceSolutionKey;
use Google\Service\Calendar\CreateConferenceRequest;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Calendar\EventExtendedProperties;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inserta, actualiza o cancela el evento de Google Calendar de una tarea
 * `reunion`. Idempotente: usa un ID determinístico por tenant+tarea+generación,
 * y ante un 409 (evento cancelado que Google no permite reinsertar — ver
 * https://developers.google.com/calendar/api/guides/errors) reintenta con
 * `update` en vez de `insert`.
 *
 * "Generación": al reasignar una tarea reunion, el evento del responsable
 * anterior se cancela y se crea uno nuevo para el nuevo responsable. Si la
 * tarea vuelve a reasignarse al responsable original, el ID determinístico de
 * la generación previa ya está cancelado en Google (no se puede reusar), por
 * eso cada reasignación incrementa `event_generation` y el ID lo incorpora.
 */
class SyncTaskCalendarEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

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
    ) {}

    /**
     * Cancela un evento puntual (snapshot de antes de la reasignación) sin
     * tocar el `task_calendar_syncs` actual, que ya representa la nueva
     * generación creada para el nuevo responsable.
     */
    public static function dispatchCancelExisting(int $taskId, int $ownerUserId, string $calendarId, string $eventId): void
    {
        self::dispatch($taskId, 'cancel_previous', $ownerUserId, $calendarId, $eventId);
    }

    public function handle(): void
    {
        // `cancel_previous` se resuelve con el snapshot del constructor: corre
        // igual si la tarea fue eliminada o dejó de ser reunión, que es
        // justamente cuando hay que borrar el evento que quedó en Google.
        if ($this->action === 'cancel_previous') {
            $this->cancelPrevious();

            return;
        }

        $task = Task::withoutGlobalScope(TenantScope::class)->find($this->taskId);

        if (! $task || $task->type?->value !== 'reunion') {
            return;
        }

        if ($this->action === 'refresh_conference') {
            $this->refreshConference($task);

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
        $owner = User::withoutGlobalScope(TenantScope::class)->find($this->cancelOwnerUserId);
        $connection = $owner?->googleCalendarConnection;

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
        if (! $task->assigned_to || ! $task->starts_at || ! $task->ends_at) {
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

        $connection = $task->assignedUser?->googleCalendarConnection;

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

            $this->markSynced($sync, $created);
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
            // new requestId for every new conference creation request.
            $existing = $service->events->get($calendarId, $sync->external_event_id);
            if ($existing->getConferenceData()) {
                $event->setConferenceData($existing->getConferenceData());
            }

            $event->setStatus('confirmed');
            $updated = $service->events->update($calendarId, $sync->external_event_id, $event, [
                'sendUpdates' => 'all',
                'conferenceDataVersion' => 1,
            ]);

            $this->markSynced($sync, $updated);
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

        $connection = $task->assignedUser?->googleCalendarConnection;
        $service = $connection ? GoogleCalendarClient::serviceFor($connection) : null;

        if (! $service) {
            $this->markPending($sync, 'needs_reauth');

            return;
        }

        try {
            $event = $service->events->get($sync->google_calendar_id ?: 'primary', $sync->external_event_id);
            $meetLink = $this->extractMeetLink($event);

            if ($meetLink) {
                $this->markSynced($sync, $event);

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

    private function cancel(Task $task, TaskCalendarSync $sync): void
    {
        if (! $sync->exists || ! $sync->external_event_id) {
            return;
        }

        $connection = $sync->ownerUser?->googleCalendarConnection;

        if (! $connection) {
            return;
        }

        $service = GoogleCalendarClient::serviceFor($connection);

        if (! $service) {
            return;
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

        $sync->status = 'paused';
        $sync->synced_at = now();
        $sync->save();
    }

    private function buildEvent(Task $task, TaskCalendarSync $sync): Event
    {
        $timezone = $task->meeting_timezone ?: 'America/Argentina/Buenos_Aires';

        $event = new Event([
            'id' => $sync->external_event_id,
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

        if ($task->recurrence) {
            $recurrence = str_starts_with($task->recurrence, 'RRULE:')
                ? $task->recurrence
                : 'RRULE:'.$task->recurrence;
            $event->setRecurrence([$recurrence]);
        }

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

        $event->setExtendedProperties(new EventExtendedProperties([
            'private' => [
                'crm_task_id' => (string) $task->id,
                'crm_tenant_id' => (string) $task->tenant_id,
            ],
        ]));

        return $event;
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

    private function markSynced(TaskCalendarSync $sync, Event $event): void
    {
        $sync->html_link = $event->getHtmlLink();
        $sync->meet_link = $this->extractMeetLink($event);
        $sync->status = $sync->meet_link ? 'synced' : 'pending';
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
