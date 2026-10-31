<?php

namespace App\Services\Scheduling;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\AiSchedulingConfig;
use App\Models\Task;
use App\Models\User;
use App\Support\GoogleCalendarClient;
use Carbon\CarbonImmutable;
use Google\Service\Calendar\Event;
use Illuminate\Support\Facades\Log;

/**
 * Calcula los turnos libres de un vendedor: horario de atención de la config,
 * menos los eventos de su Google Calendar y sus reuniones del CRM (incluye
 * los turnos pendientes de aprobación, que todavía no están en Google, y los
 * horarios pedidos en cambios de turno que esperan aprobación).
 *
 * $ignoreTaskId excluye una reunión del CRM y su propio evento de Google: al
 * reprogramar o aprobar un turno, el turno no choca consigo mismo.
 *
 * Usa events.list y no freeBusy.query: el scope concedido
 * (calendar.events.owned) permite el primero pero no el segundo.
 */
class AppointmentAvailabilityService
{
    /**
     * Inicios de turno libres dentro de [$from, $to), en la zona de la config.
     *
     * @return list<CarbonImmutable>
     *
     * @throws AppointmentAvailabilityException
     */
    public function availableSlots(AiSchedulingConfig $config, User $owner, CarbonImmutable $from, CarbonImmutable $to, ?CalendarRequestBudget $budget = null, ?int $ignoreTaskId = null): array
    {
        $tz = $config->timezone;
        $duration = $config->meeting_duration_minutes;

        $start = max($from->setTimezone($tz), CarbonImmutable::now($tz)->addHours($config->min_notice_hours));
        $end = min($to->setTimezone($tz), CarbonImmutable::now($tz)->startOfDay()->addDays($config->max_days_ahead + 1));

        if ($start >= $end) {
            return [];
        }

        $candidates = $this->candidateSlots($config, $start, $end);

        if ($candidates === []) {
            return [];
        }

        $busy = $this->busyIntervals($owner, $start, $end, $tz, $ignoreTaskId, $budget);

        return array_values(array_filter(
            $candidates,
            fn (CarbonImmutable $slot) => ! $this->overlapsAny($slot, $slot->addMinutes($duration), $busy),
        ));
    }

    /**
     * Si $start es un inicio de turno libre según toda la config (horario,
     * anticipación, ventana) y la agenda.
     *
     * @throws AppointmentAvailabilityException
     */
    public function isAvailable(AiSchedulingConfig $config, User $owner, CarbonImmutable $start, ?CalendarRequestBudget $budget = null, ?int $ignoreTaskId = null): bool
    {
        $start = $start->setTimezone($config->timezone);
        $day = $start->startOfDay();

        foreach ($this->availableSlots($config, $owner, $day, $day->addDay(), $budget, $ignoreTaskId) as $slot) {
            if ($slot->equalTo($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Si [$start, $end) choca con la agenda del vendedor, sin aplicar horario
     * de atención ni anticipación: lo usa la aprobación de un turno ya
     * propuesto, que puede estar dentro de la anticipación mínima, y la
     * edición manual de una reunión. Con $includeGoogle en false solo cuentan
     * las reuniones del CRM (vendedor sin Google conectado).
     *
     * @throws AppointmentAvailabilityException
     */
    public function hasConflict(User $owner, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreTaskId = null, ?CalendarRequestBudget $budget = null, bool $includeGoogle = true): bool
    {
        $busy = $this->busyIntervals($owner, $start, $end, $start->getTimezone()->getName(), $ignoreTaskId, $budget, $includeGoogle);

        return $this->overlapsAny($start, $end, $busy);
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function candidateSlots(AiSchedulingConfig $config, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $duration = $config->meeting_duration_minutes;
        $interval = max(1, $config->slot_interval_minutes);
        $workingHours = $config->working_hours ?? [];
        $slots = [];

        for ($day = $start->startOfDay(); $day < $end; $day = $day->addDay()) {
            $ranges = $workingHours[AiSchedulingConfig::WEEKDAYS[$day->dayOfWeek]] ?? [];

            foreach ($ranges as $range) {
                $rangeEnd = $day->setTimeFromTimeString($range['end']);

                for ($slot = $day->setTimeFromTimeString($range['start']); $slot->addMinutes($duration) <= $rangeEnd; $slot = $slot->addMinutes($interval)) {
                    if ($slot >= $start && $slot->addMinutes($duration) <= $end) {
                        $slots[] = $slot;
                    }
                }
            }
        }

        usort($slots, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        return $slots;
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     *
     * @throws AppointmentAvailabilityException
     */
    private function busyIntervals(User $owner, CarbonImmutable $start, CarbonImmutable $end, string $tz, ?int $ignoreTaskId = null, ?CalendarRequestBudget $budget = null, bool $includeGoogle = true): array
    {
        return [
            ...($includeGoogle ? $this->googleBusy($owner, $start, $end, $tz, $budget, $ignoreTaskId) : []),
            ...$this->crmBusy($owner, $start, $end, $ignoreTaskId),
        ];
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     *
     * @throws AppointmentAvailabilityException
     */
    private function googleBusy(User $owner, CarbonImmutable $start, CarbonImmutable $end, string $tz, ?CalendarRequestBudget $budget = null, ?int $ignoreTaskId = null): array
    {
        $connection = $owner->googleCalendarConnection()->withoutGlobalScopes()->where('tenant_id', $owner->tenant_id)->first();
        $service = $connection ? GoogleCalendarClient::serviceFor($connection, $budget) : null;

        if (! $service) {
            throw new AppointmentAvailabilityException('Google Calendar no conectado', AppointmentAvailabilityException::NOT_CONNECTED);
        }

        $busy = [];
        $pageToken = null;

        try {
            do {
                $params = [
                    'timeMin' => $start->toRfc3339String(),
                    'timeMax' => $end->toRfc3339String(),
                    'singleEvents' => true,
                    'orderBy' => 'startTime',
                    'maxResults' => 250,
                ];

                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $events = $service->events->listEvents('primary', $params);

                foreach ($events->getItems() as $event) {
                    if ($ignoreTaskId && $this->isTaskEvent($event, $ignoreTaskId, $owner)) {
                        continue;
                    }

                    $interval = $this->eventInterval($event, $events->getTimeZone() ?: $tz);

                    if ($interval) {
                        $busy[] = $interval;
                    }
                }

                $pageToken = $events->getNextPageToken();
            } while ($pageToken);
        } catch (\Exception $e) {
            Log::warning('AppointmentAvailabilityService: no se pudo leer Google Calendar', [
                'tenant_id' => $owner->tenant_id,
                'user_id' => $owner->id,
                'operation' => 'availability',
            ]);

            throw new AppointmentAvailabilityException('Error consultando Google Calendar', AppointmentAvailabilityException::GOOGLE_ERROR, $e);
        }

        return $busy;
    }

    /** Evento que SyncTaskCalendarEventJob creó para esa reunión del mismo workspace. */
    private function isTaskEvent(Event $event, int $taskId, User $owner): bool
    {
        $private = $event->getExtendedProperties()?->getPrivate() ?? [];

        return ($private['crm_task_id'] ?? null) === (string) $taskId
            && ($private['crm_tenant_id'] ?? null) === (string) $owner->tenant_id;
    }

    /**
     * Intervalo ocupado de un evento de Google, o null si no ocupa (cancelado,
     * marcado como "disponible" o rechazado por el dueño del calendario).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function eventInterval(Event $event, string $tz): ?array
    {
        if ($event->getStatus() === 'cancelled' || $event->getTransparency() === 'transparent') {
            return null;
        }

        foreach ($event->getAttendees() ?? [] as $attendee) {
            if ($attendee->getSelf() && $attendee->getResponseStatus() === 'declined') {
                return null;
            }
        }

        $eventStart = $event->getStart();
        $eventEnd = $event->getEnd();

        if (! $eventStart || ! $eventEnd) {
            return null;
        }

        // Las fechas sin hora corresponden a días de la zona del calendario,
        // no de la agenda del CRM. El inicio es inclusivo y el fin exclusivo.
        if ($eventStart->getDate()) {
            return [
                CarbonImmutable::parse($eventStart->getDate(), $tz)->startOfDay(),
                CarbonImmutable::parse($eventEnd->getDate() ?: $eventStart->getDate(), $tz)->startOfDay(),
            ];
        }

        if (! $eventStart->getDateTime() || ! $eventEnd->getDateTime()) {
            return null;
        }

        return [
            CarbonImmutable::parse($eventStart->getDateTime()),
            CarbonImmutable::parse($eventEnd->getDateTime()),
        ];
    }

    /**
     * Reuniones del CRM del vendedor que se superponen con [$start, $end),
     * más los horarios pedidos en cambios de turno pendientes de aprobación.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function crmBusy(User $owner, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreTaskId): array
    {
        // Las columnas guardan la hora local de la app sin zona: los límites
        // tienen que ir en esa misma zona o la comparación SQL corre la ventana.
        $appTz = config('app.timezone');
        $intervals = [];

        foreach ([['starts_at', 'ends_at'], ['ai_reschedule_starts_at', 'ai_reschedule_ends_at']] as [$from, $to]) {
            $rows = Task::withoutGlobalScopes()
                ->where('tenant_id', $owner->tenant_id)
                ->where('assigned_to', $owner->id)
                ->where('type', TaskType::MEETING)
                ->where('status', '!=', TaskStatus::CANCELED)
                ->whereNotNull($from)
                ->whereNotNull($to)
                ->where($from, '<', $end->setTimezone($appTz))
                ->where($to, '>', $start->setTimezone($appTz))
                ->when($ignoreTaskId, fn ($query) => $query->whereKeyNot($ignoreTaskId))
                ->get([$from, $to]);

            foreach ($rows as $task) {
                $intervals[] = [CarbonImmutable::instance($task->{$from}), CarbonImmutable::instance($task->{$to})];
            }
        }

        return $intervals;
    }

    /**
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $busy
     */
    private function overlapsAny(CarbonImmutable $start, CarbonImmutable $end, array $busy): bool
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            if ($start < $busyEnd && $busyStart < $end) {
                return true;
            }
        }

        return false;
    }
}
