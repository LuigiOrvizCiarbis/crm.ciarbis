<?php

namespace App\Services\Scheduling;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Lists and deletes events of the signed-in user's own primary Google Calendar. */
class GoogleCalendarEventService
{
    private const RFC3339 = 'Y-m-d\TH:i:sP';

    /** Event types an organizer may delete; birthday, fromGmail and workingLocation stay in Google. */
    private const DELETABLE_TYPES = [null, 'default', 'outOfOffice', 'focusTime'];

    public function __construct(
        private readonly CalendarOwnerLock $lock,
        private readonly UserGoogleCalendar $calendar,
        private readonly CalendarAvailabilityBlockService $blocks,
    ) {}

    public function list(User $user, ?string $timeMin, ?string $timeMax): array
    {
        Validator::make(['time_min' => $timeMin, 'time_max' => $timeMax], [
            'time_min' => ['required', 'string', 'date_format:'.self::RFC3339],
            'time_max' => ['required', 'string', 'date_format:'.self::RFC3339],
        ])->validate();
        $min = CarbonImmutable::createFromFormat(self::RFC3339, $timeMin);
        $max = CarbonImmutable::createFromFormat(self::RFC3339, $timeMax);
        if ($max <= $min || $min->diffInSeconds($max) > 93 * 86400) {
            throw ValidationException::withMessages(['time_max' => 'El período debe durar entre 1 segundo y 93 días.']);
        }

        return $this->calendar->run($user, app(CalendarRequestBudget::class), 'events.list', function (Calendar $calendar, GoogleCalendarConnection $connection) use ($user, $min, $max): array {
            $options = [
                'timeMin' => $min->toRfc3339String(),
                'timeMax' => $max->toRfc3339String(),
                'singleEvents' => true,
                'orderBy' => 'startTime',
                'showDeleted' => false,
                'maxResults' => 250,
            ];
            $timezone = null;
            $events = [];
            do {
                $page = $calendar->events->listEvents('primary', $options);
                $timezone ??= $page->getTimeZone();
                foreach ($page->getItems() as $event) {
                    if ($this->visible($event, $user)) {
                        $events[] = $this->dto($event, $user);
                    }
                }
                $token = $page->getNextPageToken();
                if ($token) {
                    $options['pageToken'] = $token;
                }
            } while ($token);

            return ['google_email' => $connection->google_email, 'timezone' => $timezone, 'events' => $events];
        });
    }

    public function delete(User $user, string $eventId, bool $notifyGuests): void
    {
        Validator::make(['event_id' => $eventId], ['event_id' => ['required', 'string', 'regex:/\A[0-9A-Za-z_@.\-]{1,1024}\z/']])->validate();
        $this->lock->run($user->id, function (CalendarRequestBudget $budget) use ($user, $eventId, $notifyGuests): void {
            $this->calendar->run($user, $budget, 'events.delete', function (Calendar $calendar) use ($user, $eventId, $notifyGuests, $budget): void {
                $event = $this->calendar->findEvent($calendar, $eventId);
                if (! $event || $this->cancelled($event)) {
                    return;
                }
                // Own availability blocks keep their dedicated endpoint; CRM meetings are cancelled through their task.
                if ($this->deletableAs($event, $user) !== 'event') {
                    throw new CalendarEventException('event_not_deletable');
                }
                $budget->assertAvailable();
                try {
                    // An instance id (singleEvents=true) deletes only that occurrence, never the series.
                    $calendar->events->delete('primary', $eventId, ['sendUpdates' => $notifyGuests && $this->hasGuests($event) ? 'all' : 'none']);
                } catch (GoogleException $e) {
                    if (! in_array((int) $e->getCode(), [404, 410], true)) {
                        throw $e;
                    }
                }
            });
        });
    }

    private function visible(Event $event, User $user): bool
    {
        if ($this->cancelled($event) || $event->getEventType() === 'workingLocation' || ! $event->getStart() || ! $event->getEnd()) {
            return false;
        }
        foreach ($event->getAttendees() ?? [] as $attendee) {
            if ($attendee->getSelf() === true && $attendee->getResponseStatus() === 'declined') {
                return false;
            }
        }
        // Meetings the active workspace syncs from Tasks already show up as tasks.
        $private = $this->privateProperties($event);

        return ! (isset($private['crm_task_id']) && ($private['crm_tenant_id'] ?? null) === (string) $user->tenant_id);
    }

    private function dto(Event $event, User $user): array
    {
        $start = $event->getStart();
        $end = $event->getEnd();
        $allDay = (bool) $start->getDate();

        return [
            'id' => $event->getId(),
            'summary' => $event->getSummary() ?: null,
            'all_day' => $allDay,
            'start' => $allDay ? $start->getDate() : $start->getDateTime(),
            'end' => $allDay ? $end->getDate() : $end->getDateTime(),
            'html_link' => $event->getHtmlLink(),
            'kind' => $this->kind($event),
            'can_delete' => $this->deletableAs($event, $user) !== null,
            'has_guests' => $this->hasGuests($event),
            'recurring' => $event->getRecurringEventId() !== null,
        ];
    }

    private function deletableAs(Event $event, User $user): ?string
    {
        return match ($this->kind($event)) {
            'availability_block' => $this->blocks->isOwnBlock($event, $user) ? 'availability_block' : null,
            'event' => $event->getOrganizer()?->getSelf() === true && in_array($event->getEventType(), self::DELETABLE_TYPES, true) ? 'event' : null,
            default => null,
        };
    }

    private function kind(Event $event): string
    {
        $private = $this->privateProperties($event);
        if (($private['crm_availability_block'] ?? null) === '1') {
            return 'availability_block';
        }

        return isset($private['crm_task_id']) ? 'crm_meeting' : 'event';
    }

    private function hasGuests(Event $event): bool
    {
        foreach ($event->getAttendees() ?? [] as $attendee) {
            if ($attendee->getSelf() !== true && $attendee->getResource() !== true) {
                return true;
            }
        }

        return false;
    }

    private function cancelled(Event $event): bool
    {
        return in_array($event->getStatus(), ['cancelled', 'canceled'], true);
    }

    private function privateProperties(Event $event): array
    {
        return $event->getExtendedProperties()?->getPrivate() ?? [];
    }
}
