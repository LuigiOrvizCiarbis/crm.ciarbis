<?php

namespace App\Services\Scheduling;

use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Support\GoogleCalendarClient;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Exception as GoogleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Access to the signed-in user's own primary Google Calendar. */
class UserGoogleCalendar
{
    public function run(User $user, CalendarRequestBudget $budget, string $operation, callable $callback): mixed
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
        } catch (CalendarAvailabilityBlockException|CalendarEventException|ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Google Calendar user operation failed', [
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

    public function findEvent(Calendar $calendar, string $eventId): ?Event
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
