<?php

namespace App\Services\Scheduling;

use App\Enums\AiBookingStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\AiSchedulingConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use App\Services\SystemMessageSender;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Crea y reprograma turnos desde la IA y aplica la decisión del vendedor
 * sobre los pendientes y los pedidos de cambio. El turno es una Task
 * `reunion`; el evento de Google lo crea o mueve SyncTaskCalendarEventJob
 * cuando el turno está confirmado.
 */
class AppointmentBookingService
{
    public function __construct(
        private AppointmentAvailabilityService $availability,
        private SystemMessageSender $sender,
        private CalendarOwnerLock $ownerLock,
    ) {}

    /**
     * @throws AppointmentBookingException
     * @throws AppointmentAvailabilityException
     */
    public function book(
        Conversation $conversation,
        AiSchedulingConfig $config,
        User $owner,
        CarbonImmutable $start,
        ?string $email,
        ?string $reason,
        bool $direct,
    ): Task {
        $task = $this->ownerLock->run($owner->id, function (CalendarRequestBudget $budget) use ($conversation, $config, $owner, $start, $email, $reason, $direct) {
            $existing = $this->activeBooking($conversation);

            if ($existing) {
                throw new AppointmentBookingException(AppointmentBookingException::ACTIVE_BOOKING_EXISTS, $existing);
            }

            if (! $this->availability->isAvailable($config, $owner, $start, $budget)) {
                throw new AppointmentBookingException(AppointmentBookingException::SLOT_UNAVAILABLE);
            }

            // Último chequeo antes de persistir: un humano pudo tomar la
            // conversación mientras el modelo generaba o se consultaba Google.
            $autoreplyEnabled = Conversation::withoutGlobalScopes()
                ->whereKey($conversation->id)
                ->value('ai_autoreply_enabled');

            if (! $autoreplyEnabled) {
                throw new AppointmentBookingException(AppointmentBookingException::HANDOFF);
            }

            $contact = $conversation->contact_id
                ? Contact::withoutGlobalScopes()->find($conversation->contact_id)
                : null;
            $startsAt = $start->setTimezone(config('app.timezone'));

            $description = 'Turno agendado por el asistente de IA desde la conversación.';
            if ($reason !== null && trim($reason) !== '') {
                $description .= "\nMotivo: ".trim($reason);
            }

            $budget->assertAvailable();

            return Task::create([
                'tenant_id' => $conversation->tenant_id,
                'contact_id' => $conversation->contact_id,
                'conversation_id' => $conversation->id,
                'assigned_to' => $owner->id,
                'name' => 'Reunión con '.($contact?->name ?: 'cliente'),
                'status' => TaskStatus::NEW,
                'priority' => TaskPriority::MEDIUM,
                'type' => TaskType::MEETING,
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->addMinutes($config->meeting_duration_minutes),
                'deadline' => $startsAt,
                'meeting_timezone' => $config->timezone,
                'meeting_guest_email' => $email,
                'description' => $description,
                'reminders' => [],
                'depends_on' => [],
                'checklist' => [],
                'attachments' => [],
                'synced_calendars' => [],
                'ai_booking_status' => $direct ? AiBookingStatus::CONFIRMED : AiBookingStatus::PENDING,
            ]);
        });

        if ($direct) {
            SyncTaskCalendarEventJob::dispatch($task->id, 'upsert');
        }

        return $task;
    }

    /**
     * Mueve el turno vigente de la conversación a $start respetando el modo:
     * un pendiente se mueve y sigue pendiente; un confirmado se mueve si
     * $direct, o queda con un pedido de cambio que aprueba un asesor (el
     * horario confirmado sigue vigente hasta entonces).
     *
     * @return array{0: Task, 1: RescheduleOutcome}
     *
     * @throws AppointmentBookingException no_active_booking | booking_changed | handoff | slot_unavailable
     * @throws AppointmentAvailabilityException
     */
    public function reschedule(Conversation $conversation, AiSchedulingConfig $config, User $owner, CarbonImmutable $start, bool $direct): array
    {
        [$task, $outcome] = $this->ownerLock->run($owner->id, function (CalendarRequestBudget $budget) use ($conversation, $config, $owner, $start, $direct): array {
            $task = $this->activeBooking($conversation);

            if (! $task) {
                throw new AppointmentBookingException(AppointmentBookingException::NO_ACTIVE_BOOKING);
            }

            // La disponibilidad consultada es la de $owner: un turno que quedó
            // en otro calendario no se mueve desde esta conversación.
            if ((int) $task->assigned_to !== (int) $owner->id) {
                throw new AppointmentBookingException(AppointmentBookingException::BOOKING_CHANGED, $task);
            }

            $autoreplyEnabled = Conversation::withoutGlobalScopes()
                ->whereKey($conversation->id)
                ->value('ai_autoreply_enabled');

            if (! $autoreplyEnabled) {
                throw new AppointmentBookingException(AppointmentBookingException::HANDOFF);
            }

            if ($start->equalTo(CarbonImmutable::instance($task->starts_at))) {
                // Volver al horario actual retira un pedido de cambio pendiente.
                if ($task->ai_reschedule_starts_at) {
                    $this->updateIfUnchanged($task, $owner, [
                        'ai_reschedule_starts_at' => null,
                        'ai_reschedule_ends_at' => null,
                        'ai_reschedule_requested_at' => null,
                    ]);
                }

                return [$task, RescheduleOutcome::UNCHANGED];
            }

            if (! $this->availability->isAvailable($config, $owner, $start, $budget, $task->id)) {
                throw new AppointmentBookingException(AppointmentBookingException::SLOT_UNAVAILABLE);
            }

            $end = $start->addMinutes($config->meeting_duration_minutes);
            $outcome = match (true) {
                $task->ai_booking_status === AiBookingStatus::PENDING => RescheduleOutcome::MOVED_PENDING,
                $direct => RescheduleOutcome::MOVED,
                default => RescheduleOutcome::REQUESTED,
            };

            $budget->assertAvailable();
            $this->updateIfUnchanged($task, $owner, $outcome === RescheduleOutcome::REQUESTED
                ? [
                    'ai_reschedule_starts_at' => self::forDatabase($start),
                    'ai_reschedule_ends_at' => self::forDatabase($end),
                    'ai_reschedule_requested_at' => now(),
                ]
                : [
                    ...$this->movedTo($start, $end),
                    'ai_reschedule_starts_at' => null,
                    'ai_reschedule_ends_at' => null,
                    'ai_reschedule_requested_at' => null,
                ]);

            return [$task, $outcome];
        });

        if ($outcome === RescheduleOutcome::MOVED) {
            DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatch($task->id, 'upsert'));
        }

        $task->refresh();

        return [$task, $outcome];
    }

    /** Turno confirmado con un pedido de cambio de horario sin resolver. */
    public function hasPendingReschedule(Task $task): bool
    {
        return $task->ai_booking_status === AiBookingStatus::CONFIRMED
            && $task->status !== TaskStatus::CANCELED
            && $task->type === TaskType::MEETING
            && $task->ai_reschedule_starts_at !== null
            && $task->ai_reschedule_ends_at !== null;
    }

    /**
     * Aplica el cambio de horario pedido y avisa al contacto. Devuelve si el
     * aviso se pudo enviar (el cambio se aplica igual).
     *
     * @throws AppointmentBookingException already_decided | slot_conflict
     * @throws AppointmentAvailabilityException
     */
    public function approveReschedule(Task $task, User $actor): bool
    {
        if (! $this->hasPendingReschedule($task)) {
            throw new AppointmentBookingException(AppointmentBookingException::ALREADY_DECIDED);
        }

        $owner = $this->owner($task);
        $tz = $task->meeting_timezone ?: config('app.timezone');
        $start = CarbonImmutable::instance($task->ai_reschedule_starts_at)->setTimezone($tz);
        $end = CarbonImmutable::instance($task->ai_reschedule_ends_at)->setTimezone($tz);

        $this->ownerLock->run($owner->id, function (CalendarRequestBudget $budget) use ($task, $owner, $start, $end, $actor) {
            if ($this->availability->hasConflict($owner, $start, $end, $task->id, $budget)) {
                throw new AppointmentBookingException(AppointmentBookingException::SLOT_CONFLICT);
            }

            $budget->assertAvailable();
            $this->resolveReschedule($task, $actor, $this->movedTo($start, $end));
        });

        DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatch($task->id, 'upsert'));

        $task->refresh();

        return $this->notifyContact($task, 'rescheduled');
    }

    /**
     * Descarta el cambio de horario pedido (el turno sigue como estaba) y
     * avisa al contacto.
     *
     * @throws AppointmentBookingException already_decided
     */
    public function rejectReschedule(Task $task, User $actor): bool
    {
        if (! $this->hasPendingReschedule($task)) {
            throw new AppointmentBookingException(AppointmentBookingException::ALREADY_DECIDED);
        }

        $proposed = CarbonImmutable::instance($task->ai_reschedule_starts_at);
        $this->resolveReschedule($task, $actor, []);
        $task->refresh();

        return $this->notifyContact($task, 'reschedule_rejected', $proposed);
    }

    /**
     * Confirma un turno pendiente y avisa al contacto. Devuelve si el aviso
     * se pudo enviar (la confirmación se aplica igual).
     *
     * @throws AppointmentBookingException already_decided | slot_conflict
     * @throws AppointmentAvailabilityException
     */
    public function approve(Task $task, User $actor): bool
    {
        if (! $this->isDecidable($task)) {
            throw new AppointmentBookingException(AppointmentBookingException::ALREADY_DECIDED);
        }

        $owner = $this->owner($task);

        $tz = $task->meeting_timezone ?: config('app.timezone');
        $start = CarbonImmutable::instance($task->starts_at)->setTimezone($tz);
        $end = CarbonImmutable::instance($task->ends_at)->setTimezone($tz);

        $this->ownerLock->run($owner->id, function (CalendarRequestBudget $budget) use ($task, $owner, $start, $end, $actor) {
            // Entre la propuesta y la aprobación alguien pudo ocupar el
            // horario en Google; la propia propuesta no cuenta como choque.
            if ($this->availability->hasConflict($owner, $start, $end, $task->id, $budget)) {
                throw new AppointmentBookingException(AppointmentBookingException::SLOT_CONFLICT);
            }

            $budget->assertAvailable();
            $this->decide($task, $actor, [
                'ai_booking_status' => AiBookingStatus::CONFIRMED->value,
            ]);
        });

        DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatch($task->id, 'upsert'));

        $task->refresh();

        return $this->notifyContact($task, 'confirmed');
    }

    /**
     * Rechaza un turno pendiente (queda cancelado) y avisa al contacto.
     *
     * @throws AppointmentBookingException already_decided
     */
    public function reject(Task $task, User $actor): bool
    {
        $this->decide($task, $actor, [
            'ai_booking_status' => AiBookingStatus::REJECTED->value,
            'status' => TaskStatus::CANCELED->value,
        ]);

        $task->refresh();

        return $this->notifyContact($task, 'rejected');
    }

    /**
     * Turno pendiente o confirmado, futuro y no cancelado de la conversación.
     */
    public function activeBooking(Conversation $conversation): ?Task
    {
        return Task::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->whereIn('ai_booking_status', [AiBookingStatus::PENDING->value, AiBookingStatus::CONFIRMED->value])
            ->where('status', '!=', TaskStatus::CANCELED->value)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Fecha y hora del turno en su zona, para los mensajes al cliente.
     *
     * @return array{0: string, 1: string} [fecha, hora]
     */
    public static function formatWhen(Task $task): array
    {
        return self::formatInstant($task->starts_at, $task->meeting_timezone);
    }

    /**
     * @return array{0: string, 1: string} [fecha, hora]
     */
    public static function formatInstant(CarbonInterface $at, ?string $timezone): array
    {
        $local = CarbonImmutable::instance($at)
            ->setTimezone($timezone ?: config('app.timezone'))
            ->locale('es');

        return [$local->isoFormat('dddd D [de] MMMM'), $local->format('H:i')];
    }

    /** Responsable activo del turno, cuyo calendario se consulta y actualiza. */
    private function owner(Task $task): User
    {
        $owner = $task->assigned_to
            ? User::withoutGlobalScopes()
                ->whereHas('activeMemberships', fn ($query) => $query->where('tenant_id', $task->tenant_id))
                ->find($task->assigned_to)
            : null;

        if (! $owner) {
            throw new AppointmentAvailabilityException('Turno sin responsable', AppointmentAvailabilityException::NOT_CONNECTED);
        }
        $owner->setAttribute('tenant_id', $task->tenant_id);

        return $owner;
    }

    /**
     * Update atómico de un turno de la IA solo si sigue como se leyó: mismo
     * estado, calendario e intervalo. Una edición concurrente gana.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws AppointmentBookingException booking_changed
     */
    private function updateIfUnchanged(Task $task, User $owner, array $changes): void
    {
        $updated = Task::withoutGlobalScopes()
            ->whereKey($task->id)
            ->where('type', TaskType::MEETING->value)
            ->where('status', '!=', TaskStatus::CANCELED->value)
            ->where('ai_booking_status', $task->ai_booking_status->value)
            ->where('assigned_to', $owner->id)
            ->where('starts_at', $task->starts_at)
            ->where('ends_at', $task->ends_at)
            ->update([...$changes, 'updated_at' => now()]);

        if ($updated === 0) {
            throw new AppointmentBookingException(AppointmentBookingException::BOOKING_CHANGED, $task);
        }
    }

    /**
     * Cierra el pedido de cambio si sigue siendo el que se leyó; con dos
     * decisiones simultáneas solo una afecta la fila.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws AppointmentBookingException already_decided
     */
    private function resolveReschedule(Task $task, User $actor, array $changes): void
    {
        $updated = Task::withoutGlobalScopes()
            ->whereKey($task->id)
            ->where('type', TaskType::MEETING->value)
            ->where('status', '!=', TaskStatus::CANCELED->value)
            ->where('ai_booking_status', AiBookingStatus::CONFIRMED->value)
            ->where('assigned_to', $task->assigned_to)
            ->where('ai_reschedule_starts_at', $task->ai_reschedule_starts_at)
            ->where('ai_reschedule_ends_at', $task->ai_reschedule_ends_at)
            ->update([
                ...$changes,
                'ai_reschedule_starts_at' => null,
                'ai_reschedule_ends_at' => null,
                'ai_reschedule_requested_at' => null,
                'ai_booking_decided_by' => $actor->id,
                'ai_booking_decided_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new AppointmentBookingException(AppointmentBookingException::ALREADY_DECIDED);
        }
    }

    /** @return array<string, string> */
    private function movedTo(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'starts_at' => self::forDatabase($start),
            'ends_at' => self::forDatabase($end),
            'deadline' => self::forDatabase($start),
        ];
    }

    /**
     * Los update por query no pasan por los mutators de Task: las columnas
     * guardan la hora de pared de la app sin zona.
     */
    private static function forDatabase(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function isDecidable(Task $task): bool
    {
        return $task->ai_booking_status === AiBookingStatus::PENDING
            && $task->status !== TaskStatus::CANCELED
            && $task->type === TaskType::MEETING;
    }

    /**
     * Transición atómica desde pending: con dos decisiones simultáneas
     * (Aceptar en el chat y Rechazar en Tareas) solo una afecta la fila; la
     * otra recibe already_decided y no encola ni notifica.
     *
     * @param  array<string, mixed>  $changes
     */
    private function decide(Task $task, User $actor, array $changes): void
    {
        $query = Task::withoutGlobalScopes()
            ->whereKey($task->id)
            ->where('ai_booking_status', AiBookingStatus::PENDING->value)
            ->where('status', '!=', TaskStatus::CANCELED->value)
            ->where('type', TaskType::MEETING->value);

        if ($changes['ai_booking_status'] === AiBookingStatus::CONFIRMED->value) {
            // La aprobación vale solo para el calendario y el intervalo que
            // se consultaron; una edición concurrente exige volver a validar.
            $query->where('assigned_to', $task->assigned_to)
                ->where('starts_at', $task->starts_at)
                ->where('ends_at', $task->ends_at);
        }

        $updated = $query->update([
            ...$changes,
            'ai_booking_decided_by' => $actor->id,
            'ai_booking_decided_at' => now(),
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            throw new AppointmentBookingException(AppointmentBookingException::ALREADY_DECIDED);
        }
    }

    private function notifyContact(Task $task, string $decision, ?CarbonInterface $proposed = null): bool
    {
        $conversation = $task->conversation_id
            ? Conversation::withoutGlobalScopes()->where('tenant_id', $task->tenant_id)->find($task->conversation_id)
            : null;

        if (! $conversation) {
            return false;
        }

        [$date, $time] = self::formatWhen($task);
        [$proposedDate, $proposedTime] = $proposed ? self::formatInstant($proposed, $task->meeting_timezone) : [null, null];

        $text = match ($decision) {
            'confirmed' => "¡Listo! Tu turno quedó confirmado para el {$date} a las {$time} hs.",
            'rescheduled' => "¡Listo! Tu turno se cambió al {$date} a las {$time} hs.",
            'reschedule_rejected' => "No pudimos cambiar tu turno al {$proposedDate} a las {$proposedTime} hs. Seguís con tu turno del {$date} a las {$time} hs. ¿Querés que busquemos otro horario?",
            default => "Lo sentimos, no podemos confirmar el turno del {$date} a las {$time} hs. ¿Te sirve otro horario?",
        };

        try {
            $this->sender->send($conversation, $text);

            return true;
        } catch (\Throwable $e) {
            // Típico: la ventana de 24 h de WhatsApp cerrada. La decisión ya
            // quedó aplicada; la UI avisa que el cliente no fue notificado.
            Log::warning('AppointmentBookingService: no se pudo avisar al contacto', [
                'task_id' => $task->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
