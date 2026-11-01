<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Scheduling\AppointmentAvailabilityException;
use App\Services\Scheduling\CalendarEventException;
use App\Services\Scheduling\GoogleCalendarEventService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GoogleCalendarEventController extends Controller
{
    public function __construct(private readonly GoogleCalendarEventService $events) {}

    public function index(Request $request): Response
    {
        $input = $request->validate([
            'time_min' => ['required', 'string'],
            'time_max' => ['required', 'string'],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'calendar_id' => ['prohibited'],
        ]);

        return $this->respond(fn () => response()->json(['data' => $this->events->list(
            $request->user(), $input['time_min'], $input['time_max'],
        )]));
    }

    public function destroy(Request $request, string $eventId): Response
    {
        $request->validate([
            'notify_guests' => ['sometimes', 'boolean'],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'calendar_id' => ['prohibited'],
        ]);

        return $this->respond(function () use ($request, $eventId): Response {
            $this->events->delete($request->user(), $eventId, $request->boolean('notify_guests'));

            return response()->noContent();
        });
    }

    private function respond(callable $action): Response
    {
        try {
            return $action();
        } catch (CalendarEventException $e) {
            return response()->json([
                'code' => 'event_not_deletable',
                'message' => 'Este evento no se puede eliminar desde el CRM. Gestionalo desde Google Calendar.',
            ], 409);
        } catch (AppointmentAvailabilityException $e) {
            if ($e->getCode() === AppointmentAvailabilityException::NOT_CONNECTED) {
                return response()->json([
                    'code' => 'calendar_not_connected',
                    'message' => 'Conectá o reconectá tu Google Calendar para ver y eliminar tus eventos.',
                ], 422);
            }
            if ($e->getCode() === AppointmentAvailabilityException::BUSY) {
                return response()->json(['code' => 'calendar_busy', 'message' => 'La agenda está siendo actualizada. Volvé a intentar.'], 409);
            }

            return response()->json([
                'code' => 'calendar_google_error',
                'message' => 'No se pudo consultar/actualizar Google Calendar. Volvé a intentar.',
            ], 503);
        }
    }
}
