<?php

namespace App\Services\Scheduling;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Support\GoogleCalendarClient;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CalendarAvailabilityBlockService
{
    public function __construct(
        private readonly CalendarOwnerLock $lock,
        private readonly AppointmentAvailabilityService $availability,
    ) {}

    public function list(User $user, ?string $fromDate, ?string $throughDate): array
    {
        return $this->withCalendar($user, app(CalendarRequestBudget::class), 'list', function (Calendar $calendar, GoogleCalendarConnection $connection) use ($user, $fromDate, $throughDate): array {
            return $this->listEvents($calendar, $connection, $user, $fromDate, $throughDate);
        });
    }

    public function create(User $user, array $input): array
    {
        $input = $this->validateInput($input);
        $fingerprint = $this->fingerprint($input);
        $eventId = 'crmblock'.hash('sha256', $user->tenant_id.':'.$user->id.':'.$input['request_id']);

        return $this->lock->run($user->id, function (CalendarRequestBudget $budget) use ($user, $input, $fingerprint, $eventId): array {
            return $this->withCalendar($user, $budget, 'create', function (Calendar $calendar) use ($user, $input, $fingerprint, $eventId, $budget): array {
                // Recover a committed write before applying today's insert-only rules.
                $existing = $this->findEvent($calendar, $eventId);
                if ($existing) {
                    return $this->recover($calendar, $existing, $user, $fingerprint);
                }

                $timezone = $this->calendarTimezone($calendar);
                if ($timezone !== $input['timezone']) {
                    throw new CalendarAvailabilityBlockException('calendar_timezone_changed');
                }
                [$start, $end, $allDay] = $this->newInterval($input, $timezone);
                if (! ($input['confirm_overlap'] ?? false) && $this->availability->hasConflict($user, $start, $end, ignoreTaskId: null, budget: $budget)) {
                    throw new CalendarAvailabilityBlockException('overlap_confirmation_required');
                }

                $event = new Event([
                    'id' => $eventId,
                    'summary' => 'No disponible',
                    'transparency' => 'opaque',
                    'visibility' => 'private',
                    'reminders' => ['useDefault' => false],
                    'extendedProperties' => ['private' => [
                        ...$this->markers($user),
                        'crm_block_fingerprint' => $fingerprint,
                    ]],
                    'start' => $allDay ? ['date' => $start->format('Y-m-d')] : ['dateTime' => $start->toRfc3339String(), 'timeZone' => $timezone],
                    'end' => $allDay ? ['date' => $end->format('Y-m-d')] : ['dateTime' => $end->toRfc3339String(), 'timeZone' => $timezone],
                ]);
                $budget->assertAvailable();
                try {
                    $created = $calendar->events->insert('primary', $event, ['sendUpdates' => 'none']);
                } catch (GoogleException $e) {
                    if ((int) $e->getCode() !== 409) {
                        throw $e;
                    }
                    $existing = $this->findEvent($calendar, $eventId);
                    if (! $existing) {
                        throw new CalendarAvailabilityBlockException('block_request_conflict');
                    }

                    return $this->recover($calendar, $existing, $user, $fingerprint);
                }

                return ['block' => $this->dto($created, $timezone), 'created' => true];
            });
        });
    }

    public function delete(User $user, string $eventId): void
    {
        Validator::make(['event_id' => $eventId], ['event_id' => ['required', 'string', 'regex:/\A[0-9a-v]{5,1024}\z/']])->validate();
        $this->lock->run($user->id, function (CalendarRequestBudget $budget) use ($user, $eventId): void {
            $this->withCalendar($user, $budget, 'delete', function (Calendar $calendar) use ($user, $eventId, $budget): void {
                $event = $this->findEvent($calendar, $eventId);
                if (! $event) {
                    return;
                }
                if (! $this->belongsTo($event, $user)) {
                    throw new CalendarAvailabilityBlockException('not_found');
                }
                if (in_array($event->getStatus(), ['cancelled', 'canceled'], true)) {
                    return;
                }
                $budget->assertAvailable();
                try {
                    $calendar->events->delete('primary', $eventId, ['sendUpdates' => 'none']);
                } catch (GoogleException $e) {
                    if (! in_array((int) $e->getCode(), [404, 410], true)) {
                        throw $e;
                    }
                }
            });
        });
    }

    /** Shared by the HTTP boundary and direct service callers. */
    public function validateInput(array $input): array
    {
        $mode = $input['mode'] ?? null;
        $validated = Validator::make($input, [
            'request_id' => ['required', 'uuid', 'regex:/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))],
            'mode' => ['required', Rule::in(['rest_of_day', 'time_range', 'full_days'])],
            'starts_at' => [$mode === 'time_range' ? 'required' : 'prohibited', 'string'],
            'ends_at' => [$mode === 'time_range' ? 'required' : 'prohibited', 'string'],
            'start_date' => [$mode === 'full_days' ? 'required' : 'prohibited', 'string'],
            'end_date' => [$mode === 'full_days' ? 'required' : 'prohibited', 'string'],
            'confirm_overlap' => ['sometimes', 'boolean'],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'calendar_id' => ['prohibited'],
        ])->validate();
        if ($mode === 'time_range') {
            $this->strictDate($validated['starts_at'], 'Y-m-d H:i', $validated['timezone'], 'starts_at');
            $this->strictDate($validated['ends_at'], 'Y-m-d H:i', $validated['timezone'], 'ends_at');
        } elseif ($mode === 'full_days') {
            $this->strictDate($validated['start_date'], 'Y-m-d', $validated['timezone'], 'start_date');
            $this->strictDate($validated['end_date'], 'Y-m-d', $validated['timezone'], 'end_date');
        }

        return $validated;
    }

    private function withCalendar(User $user, CalendarRequestBudget $budget, string $operation, callable $callback): mixed
    {
        $connection = GoogleCalendarConnection::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->first();
        if (! $connection || $connection->status !== 'connected') {
            throw new AppointmentAvailabilityException('Google Calendar no conectado', AppointmentAvailabilityException::NOT_CONNECTED);
        }
        try {
            $calendar = GoogleCalendarClient::serviceFor($connection, $budget);
            if (! $calendar) {
                throw new AppointmentAvailabilityException('Google Calendar no conectado', AppointmentAvailabilityException::NOT_CONNECTED);
            }
            return $callback($calendar, $connection);
        } catch (CalendarAvailabilityBlockException|ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Calendar availability block operation failed', [
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'operation' => $operation,
            ]);
            $cause = $e;
            while ($cause->getPrevious()) {
                $cause = $cause->getPrevious();
            }
            if ($cause instanceof GoogleException && $this->insufficientScope($cause)) {
                GoogleCalendarClient::markNeedsReauth($connection, 'insufficient_calendar_scope');
                throw new AppointmentAvailabilityException('Google Calendar no conectado', AppointmentAvailabilityException::NOT_CONNECTED, $e);
            }
            if ($e instanceof AppointmentAvailabilityException) {
                throw $e;
            }
            throw new AppointmentAvailabilityException('Error consultando Google Calendar', AppointmentAvailabilityException::GOOGLE_ERROR, $e);
        }
    }

    private function listEvents(Calendar $calendar, GoogleCalendarConnection $connection, User $user, ?string $fromDate, ?string $throughDate): array
    {
        $timezone = $this->calendarTimezone($calendar);
        if ($fromDate === null && $throughDate === null) {
            $from = CarbonImmutable::now($timezone)->startOfDay();
            $through = $from->addDays(30);
        } else {
            if ($fromDate === null || $throughDate === null) {
                throw ValidationException::withMessages(['from_date' => 'Indicá ambas fechas del período.']);
            }
            $from = $this->strictDate($fromDate, 'Y-m-d', $timezone, 'from_date');
            $through = $this->strictDate($throughDate, 'Y-m-d', $timezone, 'through_date');
            if ($through < $from || $from->diffInDays($through) > 92) {
                throw ValidationException::withMessages(['through_date' => 'El período debe tener entre 1 y 93 días inclusive.']);
            }
        }
        $options = [
            'timeMin' => $from->toRfc3339String(),
            'timeMax' => $through->addDay()->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
            'showDeleted' => false,
            'maxResults' => 250,
            'privateExtendedProperty' => array_map(fn ($key, $value) => $key.'='.$value, array_keys($this->markers($user)), array_values($this->markers($user))),
        ];
        $blocks = [];
        do {
            $page = $calendar->events->listEvents('primary', $options);
            foreach ($page->getItems() as $event) {
                if ($this->belongsTo($event, $user) && $this->isBlocking($event)) {
                    $blocks[] = $this->dto($event, $timezone);
                }
            }
            $token = $page->getNextPageToken();
            if ($token) {
                $options['pageToken'] = $token;
            }
        } while ($token);

        return [
            'google_email' => $connection->google_email,
            'timezone' => $timezone,
            'from_date' => $from->format('Y-m-d'),
            'through_date' => $through->format('Y-m-d'),
            'blocks' => $blocks,
        ];
    }

    private function calendarTimezone(Calendar $calendar): string
    {
        $timezone = $calendar->events->listEvents('primary', ['maxResults' => 1])->getTimeZone();
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new AppointmentAvailabilityException('Zona de Google Calendar inválida', AppointmentAvailabilityException::GOOGLE_ERROR);
        }

        return $timezone;
    }

    private function findEvent(Calendar $calendar, string $eventId): ?Event
    {
        try {
            return $calendar->events->get('primary', $eventId);
        } catch (GoogleException $e) {
            if (in_array((int) $e->getCode(), [404, 410], true)) {
                return null;
            }
            throw $e;
        }
    }

    private function recover(Calendar $calendar, Event $event, User $user, string $fingerprint): array
    {
        if (! $this->belongsTo($event, $user) || ! $this->isBlocking($event)
            || ($event->getExtendedProperties()?->getPrivate()['crm_block_fingerprint'] ?? null) !== $fingerprint) {
            throw new CalendarAvailabilityBlockException('block_request_conflict');
        }

        return ['block' => $this->dto($event, $this->calendarTimezone($calendar)), 'created' => false];
    }

    private function newInterval(array $input, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);
        $today = $now->startOfDay();
        if ($input['mode'] === 'rest_of_day') {
            // startOfDay() after addDay(): a midnight DST jump makes "00:00" 01:00.
            return [$now->startOfMinute(), $now->addDay()->startOfDay(), false];
        }
        $allDay = $input['mode'] === 'full_days';
        $start = $this->strictDate($input[$allDay ? 'start_date' : 'starts_at'], $allDay ? 'Y-m-d' : 'Y-m-d H:i', $timezone, $allDay ? 'start_date' : 'starts_at');
        $end = $this->strictDate($input[$allDay ? 'end_date' : 'ends_at'], $allDay ? 'Y-m-d' : 'Y-m-d H:i', $timezone, $allDay ? 'end_date' : 'ends_at');
        if ($allDay) {
            $end = $end->addDay()->startOfDay();
        }
        if ($start < $today || $end <= $start || $end <= $now) {
            throw ValidationException::withMessages([$allDay ? 'end_date' : 'ends_at' => 'Elegí un período válido desde hoy que termine en el futuro.']);
        }

        return [$start, $end, $allDay];
    }

    private function strictDate(string $value, string $format, string $timezone, string $field): CarbonImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone($timezone));
        $errors = DateTimeImmutable::getLastErrors();
        if (! $parsed || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $parsed->format($format) !== $value) {
            throw ValidationException::withMessages([$field => 'La fecha u hora no es válida en la zona del calendario.']);
        }

        return CarbonImmutable::instance($parsed);
    }

    private function fingerprint(array $input): string
    {
        $keys = match ($input['mode']) {
            'time_range' => ['mode', 'timezone', 'starts_at', 'ends_at'],
            'full_days' => ['mode', 'timezone', 'start_date', 'end_date'],
            default => ['mode', 'timezone'],
        };
        $logical = [];
        foreach ($keys as $key) {
            $logical[$key] = $input[$key];
        }

        return hash('sha256', json_encode($logical, JSON_THROW_ON_ERROR));
    }

    private function markers(User $user): array
    {
        return ['crm_availability_block' => '1', 'crm_tenant_id' => (string) $user->tenant_id, 'crm_user_id' => (string) $user->id];
    }

    private function belongsTo(Event $event, User $user): bool
    {
        $properties = $event->getExtendedProperties()?->getPrivate() ?? [];
        foreach ($this->markers($user) as $key => $value) {
            if (($properties[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function isBlocking(Event $event): bool
    {
        return ! in_array($event->getStatus(), ['cancelled', 'canceled'], true) && $event->getTransparency() !== 'transparent';
    }

    private function dto(Event $event, string $timezone): array
    {
        $start = $event->getStart();
        $end = $event->getEnd();
        $allDay = (bool) $start?->getDate();
        if (! $start || ! $end || ! ($allDay ? $end->getDate() : ($start->getDateTime() && $end->getDateTime()))) {
            throw new AppointmentAvailabilityException('Evento de Google Calendar inválido', AppointmentAvailabilityException::GOOGLE_ERROR);
        }

        return [
            'id' => $event->getId(),
            'all_day' => $allDay,
            'start' => $allDay ? $start->getDate() : $start->getDateTime(),
            'end' => $allDay ? $end->getDate() : $end->getDateTime(),
            'timezone' => $start->getTimeZone() ?: $timezone,
            'html_link' => $event->getHtmlLink(),
        ];
    }

    private function insufficientScope(GoogleException $e): bool
    {
        if ((int) $e->getCode() !== 403) {
            return false;
        }
        foreach ($e->getErrors() ?? [] as $error) {
            if (in_array($error['reason'] ?? '', ['insufficientPermissions', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT'], true)) {
                return true;
            }
        }

        return str_contains(strtolower($e->getMessage()), 'insufficient authentication scopes');
    }
}
