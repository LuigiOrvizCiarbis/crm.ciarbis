<?php

namespace App\Services\Flows;

use App\Enums\AiBookingStatus;
use App\Enums\AiSchedulingMode;
use App\Models\AiSchedulingConfig;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use App\Services\Scheduling\AppointmentAvailabilityException;
use App\Services\Scheduling\AppointmentAvailabilityService;
use App\Services\Scheduling\AppointmentBookingException;
use App\Services\Scheduling\AppointmentBookingService;
use App\Services\Scheduling\AppointmentToolsetFactory;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Fechas, horarios y reserva de turnos para el endpoint de formularios, sobre
 * la misma agenda que usa el asistente de IA.
 */
class FlowAppointmentProvider
{
    private const MAX_DATES = 30;

    private const MAX_SLOTS = 50;

    public function __construct(
        private readonly AppointmentToolsetFactory $toolsets,
        private readonly AppointmentAvailabilityService $availability,
        private readonly AppointmentBookingService $booking,
    ) {}

    /** @return list<array{id: string, title: string}> */
    public function dates(Conversation $conversation): array
    {
        [$config, $owner] = $this->agenda($conversation);
        $now = CarbonImmutable::now($config->timezone);
        $slots = $this->slotsBetween($config, $owner, $now, $now->addDays($config->max_days_ahead + 1));

        $dates = [];
        foreach ($slots as $slot) {
            $day = $slot->setTimezone($config->timezone);
            $dates[$day->format('Y-m-d')] ??= [
                'id' => $day->format('Y-m-d'),
                'title' => $day->locale('es')->isoFormat('ddd D [de] MMM'),
            ];
        }

        if ($dates === []) {
            throw new FlowProviderException('No hay turnos disponibles.');
        }

        return array_slice(array_values($dates), 0, self::MAX_DATES);
    }

    /** @return list<array{id: string, title: string}> */
    public function slots(Conversation $conversation, string $date): array
    {
        [$config, $owner] = $this->agenda($conversation);

        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $config->timezone)
            : false;

        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new FlowProviderException('Elegí una fecha válida.');
        }

        $slots = array_map(
            fn (CarbonImmutable $slot) => [
                'id' => $slot->toIso8601String(),
                'title' => $slot->setTimezone($config->timezone)->format('H:i'),
            ],
            $this->slotsBetween($config, $owner, $day->startOfDay(), $day->startOfDay()->addDay()),
        );

        if ($slots === []) {
            throw new FlowProviderException('No quedan horarios ese día. Elegí otra fecha.');
        }

        return array_slice($slots, 0, self::MAX_SLOTS);
    }

    public function book(Conversation $conversation, string $slot, ?string $email, ?string $reason): Task
    {
        [$config, $owner] = $this->agenda($conversation);

        try {
            $start = CarbonImmutable::parse($slot);
        } catch (Throwable) {
            throw new FlowProviderException('Elegí un horario válido.');
        }

        try {
            return $this->booking->book(
                $conversation,
                $config,
                $owner,
                $start,
                $email,
                $reason,
                direct: $config->mode === AiSchedulingMode::AUTO,
                viaFlow: true,
            );
        } catch (AppointmentAvailabilityException) {
            throw new FlowProviderException('La agenda no respondió. Probá de nuevo.');
        } catch (AppointmentBookingException $e) {
            throw new FlowProviderException(match ($e->reason) {
                AppointmentBookingException::SLOT_UNAVAILABLE => 'Ese horario ya no está disponible. Elegí otro.',
                AppointmentBookingException::ACTIVE_BOOKING_EXISTS => 'Ya tenés un turno agendado.',
                default => 'No pudimos agendar el turno.',
            });
        }
    }

    public static function bookingStatus(Task $task): string
    {
        return $task->ai_booking_status === AiBookingStatus::CONFIRMED ? 'confirmed' : 'pending';
    }

    /** @return array{0: AiSchedulingConfig, 1: User} */
    private function agenda(Conversation $conversation): array
    {
        $toolset = $this->toolsets->forConversation($conversation);
        $config = AiSchedulingConfig::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('enabled', true)
            ->first();

        if ($toolset === null || $config === null) {
            throw new FlowProviderException('La agenda no está disponible en este momento.');
        }

        return [$config, $toolset->owner()];
    }

    /** @return list<CarbonImmutable> */
    private function slotsBetween(AiSchedulingConfig $config, User $owner, CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            return $this->availability->availableSlots($config, $owner, $from, $to);
        } catch (AppointmentAvailabilityException) {
            throw new FlowProviderException('La agenda no respondió. Probá de nuevo.');
        }
    }
}
