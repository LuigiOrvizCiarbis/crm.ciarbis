<?php

namespace App\Http\Controllers\Api;

use App\Enums\AiBookingStatus;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\Scheduling\AppointmentAvailabilityException;
use App\Services\Scheduling\AppointmentBookingException;
use App\Services\Scheduling\AppointmentBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aceptar / rechazar los turnos que la IA dejó pendientes de aprobación.
 */
class TaskAiBookingController extends Controller
{
    private const ALREADY_DECIDED_MESSAGE = 'El turno ya fue resuelto';

    public function approve(Request $request, Task $task, AppointmentBookingService $booking): JsonResponse
    {
        $this->authorize('update', $task);

        if (! $this->isPending($task)) {
            return response()->json(['message' => self::ALREADY_DECIDED_MESSAGE], 409);
        }

        if (! $task->starts_at || $task->starts_at->lte(now())) {
            return response()->json(['message' => 'El horario del turno ya pasó'], 422);
        }

        try {
            $notified = $booking->approve($task, $request->user());
        } catch (AppointmentBookingException $e) {
            return response()->json([
                'message' => $e->reason === AppointmentBookingException::SLOT_CONFLICT
                    ? 'El horario ya está ocupado en la agenda del responsable'
                    : self::ALREADY_DECIDED_MESSAGE,
            ], 409);
        } catch (AppointmentAvailabilityException $e) {
            return match ($e->getCode()) {
                AppointmentAvailabilityException::NOT_CONNECTED => response()->json(['message' => 'El responsable no tiene Google Calendar conectado'], 422),
                AppointmentAvailabilityException::BUSY => response()->json(['message' => 'La agenda del responsable está ocupada; probá de nuevo en unos segundos'], 409),
                default => response()->json(['message' => 'No se pudo consultar la agenda'], 503),
            };
        }

        return $this->decided($task, $notified);
    }

    public function reject(Request $request, Task $task, AppointmentBookingService $booking): JsonResponse
    {
        $this->authorize('update', $task);

        if (! $this->isPending($task)) {
            return response()->json(['message' => self::ALREADY_DECIDED_MESSAGE], 409);
        }

        try {
            $notified = $booking->reject($task, $request->user());
        } catch (AppointmentBookingException) {
            return response()->json(['message' => self::ALREADY_DECIDED_MESSAGE], 409);
        }

        return $this->decided($task, $notified);
    }

    private function isPending(Task $task): bool
    {
        return $task->ai_booking_status === AiBookingStatus::PENDING
            && $task->status !== TaskStatus::CANCELED
            && $task->type === TaskType::MEETING;
    }

    private function decided(Task $task, bool $notified): JsonResponse
    {
        return response()->json([
            'data' => $task->fresh()->load(TaskController::EAGER_LOAD),
            'contact_notified' => $notified,
        ]);
    }
}
