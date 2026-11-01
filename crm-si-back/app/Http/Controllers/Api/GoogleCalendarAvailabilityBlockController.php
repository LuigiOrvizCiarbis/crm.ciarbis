<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Scheduling\AppointmentAvailabilityException;
use App\Services\Scheduling\CalendarAvailabilityBlockException;
use App\Services\Scheduling\CalendarAvailabilityBlockService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GoogleCalendarAvailabilityBlockController extends Controller
{
    public function __construct(private readonly CalendarAvailabilityBlockService $blocks) {}

    public function index(Request $request): Response
    {
        $input = $request->validate([
            'from_date' => ['sometimes', 'required', 'string'],
            'through_date' => ['sometimes', 'required', 'string'],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'calendar_id' => ['prohibited'],
        ]);

        return $this->respond('list', fn () => response()->json(['data' => $this->blocks->list(
            $request->user(), $input['from_date'] ?? null, $input['through_date'] ?? null,
        )]));
    }

    public function store(Request $request): Response
    {
        // Validation is shared with direct domain callers; no ownership fields
        // are accepted, and local dates are parsed strictly by the service.
        $input = $this->blocks->validateInput($request->all());

        return $this->respond('create', function () use ($request, $input): Response {
            $result = $this->blocks->create($request->user(), $input);

            return response()->json(['data' => $result['block']], $result['created'] ? 201 : 200);
        });
    }

    public function destroy(Request $request, string $eventId): Response
    {
        $request->validate(['user_id' => ['prohibited'], 'tenant_id' => ['prohibited'], 'calendar_id' => ['prohibited']]);

        return $this->respond('delete', function () use ($request, $eventId): Response {
            $this->blocks->delete($request->user(), $eventId);

            return response()->noContent();
        });
    }

    private function respond(string $operation, callable $action): Response
    {
        try {
            return $action();
        } catch (CalendarAvailabilityBlockException $e) {
            $message = match ($e->reason) {
                'overlap_confirmation_required' => 'Este intervalo coincide con eventos o propuestas existentes. Se conservarán si bloqueás igualmente.',
                'calendar_timezone_changed' => 'La zona horaria de tu calendario cambió. Recargá el calendario antes de bloquear.',
                'not_found' => 'No se encontró el bloqueo.',
                default => 'Esta solicitud ya corresponde a otro bloqueo o fue modificada en Google. Iniciá una nueva solicitud.',
            };

            return response()->json(['code' => $e->reason, 'message' => $message], $e->reason === 'not_found' ? 404 : 409);
        } catch (AppointmentAvailabilityException $e) {
            if ($e->getCode() === AppointmentAvailabilityException::NOT_CONNECTED) {
                return response()->json([
                    'code' => 'calendar_not_connected',
                    'message' => 'Conectá o reconectá tu Google Calendar para bloquear tu agenda.',
                ], 422);
            }
            if ($e->getCode() === AppointmentAvailabilityException::BUSY) {
                return response()->json(['code' => 'calendar_busy', 'message' => 'La agenda está siendo actualizada. Volvé a intentar.'], 409);
            }

            return response()->json([
                'code' => 'calendar_google_error',
                'message' => $operation === 'create'
                    ? 'No se pudo confirmar el bloqueo. Reintentá para comprobarlo.'
                    : 'No se pudo consultar/actualizar Google Calendar. Volvé a intentar.',
            ], 503);
        }
    }
}
