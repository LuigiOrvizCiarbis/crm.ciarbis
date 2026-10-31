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
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Crea turnos desde la IA y aplica la decisión del vendedor sobre los
 * pendientes. El turno es una Task `reunion`; el evento de Google lo crea
 * SyncTaskCalendarEventJob cuando el turno queda confirmado.
 */
class AppointmentBookingService
{
    /**
     * TTL del lock por vendedor: cubre la consulta a Google (refresh de token
     * + events.list, cada uno con el timeout HTTP de Google Calendar) y la
     * creación de la tarea. Si venciera antes, otra reserva podría entrar.
     */
    private const LOCK_SECONDS = 60;

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private AppointmentAvailabilityService $availability,
        private SystemMessageSender $sender,
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
        $task = $this->withOwnerLock($owner->id, function () use ($conversation, $config, $owner, $start, $email, $reason, $direct) {
            $existing = $this->activeBooking($conversation);

            if ($existing) {
                throw new AppointmentBookingException(AppointmentBookingException::ACTIVE_BOOKING_EXISTS, $existing);
            }

            if (! $this->availability->isAvailable($config, $owner, $start)) {
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

        $owner = $task->assigned_to
            ? User::withoutGlobalScopes()->where('tenant_id', $task->tenant_id)->find($task->assigned_to)
            : null;

        if (! $owner) {
            throw new AppointmentAvailabilityException('Turno sin responsable', AppointmentAvailabilityException::NOT_CONNECTED);
        }

        $tz = $task->meeting_timezone ?: config('app.timezone');
        $start = CarbonImmutable::instance($task->starts_at)->setTimezone($tz);
        $end = CarbonImmutable::instance($task->ends_at)->setTimezone($tz);

        $this->withOwnerLock($owner->id, function () use ($task, $owner, $start, $end, $actor) {
            // Entre la propuesta y la aprobación alguien pudo ocupar el
            // horario en Google; la propia propuesta no cuenta como choque.
            if ($this->availability->hasConflict($owner, $start, $end, $task->id)) {
                throw new AppointmentBookingException(AppointmentBookingException::SLOT_CONFLICT);
            }

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
        $local = CarbonImmutable::instance($task->starts_at)
            ->setTimezone($task->meeting_timezone ?: config('app.timezone'))
            ->locale('es');

        return [$local->isoFormat('dddd D [de] MMMM'), $local->format('H:i')];
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
        $updated = Task::withoutGlobalScopes()
            ->whereKey($task->id)
            ->where('ai_booking_status', AiBookingStatus::PENDING->value)
            ->where('status', '!=', TaskStatus::CANCELED->value)
            ->where('type', TaskType::MEETING->value)
            ->update([
                ...$changes,
                'ai_booking_decided_by' => $actor->id,
                'ai_booking_decided_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            throw new AppointmentBookingException(AppointmentBookingException::ALREADY_DECIDED);
        }
    }

    private function notifyContact(Task $task, string $decision): bool
    {
        $conversation = $task->conversation_id
            ? Conversation::withoutGlobalScopes()->where('tenant_id', $task->tenant_id)->find($task->conversation_id)
            : null;

        if (! $conversation) {
            return false;
        }

        [$date, $time] = self::formatWhen($task);

        $text = $decision === 'confirmed'
            ? "¡Listo! Tu turno quedó confirmado para el {$date} a las {$time} hs."
            : "Lo sentimos, no podemos confirmar el turno del {$date} a las {$time} hs. ¿Te sirve otro horario?";

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

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws AppointmentAvailabilityException si otra reserva del vendedor no libera el lock a tiempo.
     */
    private function withOwnerLock(int $ownerId, callable $callback): mixed
    {
        try {
            return Cache::lock("ai-booking:owner:{$ownerId}", self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, $callback);
        } catch (LockTimeoutException $e) {
            throw new AppointmentAvailabilityException('Agenda ocupada', AppointmentAvailabilityException::BUSY, $e);
        }
    }
}
