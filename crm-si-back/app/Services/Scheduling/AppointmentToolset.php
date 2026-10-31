<?php

namespace App\Services\Scheduling;

use App\Enums\AiBookingStatus;
use App\Enums\AiSchedulingMode;
use App\Models\AiSchedulingConfig;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiTool;
use App\Services\Ai\AiToolset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Herramientas de agenda que el bot de IA usa en una conversación:
 * consultar_disponibilidad y agendar_turno, sobre el calendario de $owner.
 */
class AppointmentToolset implements AiToolset
{
    public const CHECK_AVAILABILITY = 'consultar_disponibilidad';

    public const BOOK = 'agendar_turno';

    private const AGENDA_UNAVAILABLE = 'No pude consultar la agenda en este momento. Decile al cliente que un asesor '
        .'lo va a contactar para coordinar el turno.';

    private const SEARCH_DAYS = 7;

    private ?Task $lastBooking = null;

    public function __construct(
        private Conversation $conversation,
        private AiSchedulingConfig $config,
        private User $owner,
        private AppointmentAvailabilityService $availability,
        private AppointmentBookingService $booking,
    ) {}

    public function owner(): User
    {
        return $this->owner;
    }

    public function definitions(): array
    {
        $bookProperties = [
            'inicio' => [
                'type' => 'string',
                'description' => 'Fecha y hora de inicio, formato YYYY-MM-DD HH:MM, en la zona horaria de la agenda.',
            ],
            'email' => [
                'type' => 'string',
                'description' => 'Email del cliente para enviarle la invitación, si lo dio.',
            ],
            'motivo' => [
                'type' => 'string',
                'description' => 'Resumen breve de qué quiere tratar el cliente.',
            ],
        ];
        $required = ['inicio'];

        if ($this->config->mode === AiSchedulingMode::CRITERIA) {
            $bookProperties['agendar_directo'] = [
                'type' => 'boolean',
                'description' => 'true si según el criterio de la empresa el turno se confirma directo; false si debe aprobarlo un asesor.',
            ];
            $required[] = 'agendar_directo';
        }

        return [
            new AiTool(
                self::CHECK_AVAILABILITY,
                'Consulta los horarios libres para un turno con un asesor. Usala siempre antes de ofrecer horarios; nunca inventes horarios.',
                [
                    'type' => 'object',
                    'properties' => [
                        'desde' => [
                            'type' => 'string',
                            'description' => 'Fecha desde la cual buscar, formato YYYY-MM-DD. Si se omite, desde hoy.',
                        ],
                    ],
                ],
            ),
            new AiTool(
                self::BOOK,
                'Agenda el turno en el horario que el cliente eligió explícitamente de los devueltos por consultar_disponibilidad.',
                [
                    'type' => 'object',
                    'properties' => $bookProperties,
                    'required' => $required,
                ],
            ),
        ];
    }

    public function call(string $name, array $input): string
    {
        try {
            return match ($name) {
                self::CHECK_AVAILABILITY => $this->checkAvailability($input),
                self::BOOK => $this->book($input),
                default => 'Herramienta desconocida.',
            };
        } catch (\Throwable $e) {
            Log::error('AppointmentToolset: error ejecutando herramienta', [
                'tool' => $name,
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
            ]);

            return 'Error interno al agendar. Decile al cliente que un asesor lo va a contactar.';
        }
    }

    /**
     * Último turno creado en esta generación, para avisar al cliente aunque el
     * modelo no llegue a redactar la respuesta final.
     */
    public function lastBooking(): ?Task
    {
        return $this->lastBooking;
    }

    /**
     * Mensaje fijo sobre el turno creado en esta generación, o null si no hubo.
     */
    public function bookingFallbackMessage(): ?string
    {
        if (! $this->lastBooking) {
            return null;
        }

        [$date, $time] = AppointmentBookingService::formatWhen($this->lastBooking);

        return $this->lastBooking->ai_booking_status === AiBookingStatus::CONFIRMED
            ? "Tu turno quedó confirmado para el {$date} a las {$time} hs."
            : "Registramos tu pedido de turno para el {$date} a las {$time} hs. Un asesor lo va a confirmar a la brevedad.";
    }

    /**
     * Sección "Agenda de turnos" del system prompt. Lleva solo la fecha (no la
     * hora) para no invalidar el prompt caching más de una vez por día.
     */
    public function promptSection(): string
    {
        $tz = $this->config->timezone;
        $today = CarbonImmutable::now($tz)->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');

        $modeText = match ($this->config->mode) {
            AiSchedulingMode::AUTO => 'Los turnos que agendes quedan confirmados en el momento.',
            AiSchedulingMode::APPROVAL => 'Los turnos que agendes quedan pendientes hasta que un asesor los confirme.',
            AiSchedulingMode::CRITERIA => 'Decidí en cada caso con agendar_directo si el turno se confirma directo (true) o lo '
                ."aprueba un asesor (false), según este criterio de la empresa:\n"
                .trim((string) $this->config->criteria)
                ."\nSi el criterio no deja claro el caso, usá false.",
        };

        return "## Agenda de turnos\n"
            ."Podés ofrecer y agendar turnos de {$this->config->meeting_duration_minutes} minutos con un asesor. "
            ."Hoy es {$today} (zona horaria {$tz}).\n"
            ."- Antes de ofrecer horarios usá siempre consultar_disponibilidad. Ofrecé pocas opciones (2 o 3) salvo que el cliente pida más.\n"
            ."- Usá agendar_turno solo cuando el cliente eligió explícitamente un horario de la lista.\n"
            .$modeText;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function checkAvailability(array $input): string
    {
        $tz = $this->config->timezone;
        $now = CarbonImmutable::now($tz);

        $from = is_string($input['desde'] ?? null)
            ? self::parseStrict('Y-m-d', $input['desde'], $tz)
            : null;

        if (! $from || $from < $now->startOfDay()) {
            $from = $now;
        }

        $to = $from->startOfDay()->addDays(self::SEARCH_DAYS);
        $windowEnd = $now->startOfDay()->addDays($this->config->max_days_ahead + 1);

        try {
            $slots = $this->availability->availableSlots($this->config, $this->owner, $from, $to);
        } catch (AppointmentAvailabilityException) {
            return self::AGENDA_UNAVAILABLE;
        }

        $lines = [
            "Zona horaria: {$tz}. Duración de cada turno: {$this->config->meeting_duration_minutes} minutos.",
        ];

        if ($slots === []) {
            $lines[] = "No hay horarios disponibles entre el {$from->format('d/m/Y')} y el {$to->format('d/m/Y')}.";
        } else {
            $lines[] = 'Horarios disponibles:';

            $byDay = [];
            foreach ($slots as $slot) {
                $byDay[$slot->toDateString()][] = $slot;
            }

            foreach ($byDay as $daySlots) {
                $label = $daySlots[0]->locale('es')->isoFormat('dddd DD/MM/YYYY');
                $times = implode(', ', array_map(fn (CarbonImmutable $slot) => $slot->format('H:i'), $daySlots));
                $lines[] = "- {$label}: {$times}";
            }
        }

        if ($windowEnd > $to) {
            $lines[] = "Para fechas posteriores al {$to->format('d/m/Y')}, volvé a consultar con \"desde\".";
        }

        $email = $this->contactEmail();
        $lines[] = $email
            ? "Email registrado del cliente: {$email} (no hace falta pedirlo)."
            : 'El cliente no tiene email registrado: podés pedírselo para enviarle la invitación (es opcional).';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function book(array $input): string
    {
        $tz = $this->config->timezone;
        $start = is_string($input['inicio'] ?? null)
            ? self::parseStrict('Y-m-d H:i', $input['inicio'], $tz)
            : null;

        if (! $start) {
            return 'Formato de inicio inválido. Usá YYYY-MM-DD HH:MM.';
        }

        $email = is_string($input['email'] ?? null) && filter_var($input['email'], FILTER_VALIDATE_EMAIL)
            ? $input['email']
            : null;
        $reason = is_string($input['motivo'] ?? null) ? $input['motivo'] : null;

        $direct = match ($this->config->mode) {
            AiSchedulingMode::AUTO => true,
            AiSchedulingMode::APPROVAL => false,
            AiSchedulingMode::CRITERIA => ($input['agendar_directo'] ?? false) === true,
        };

        try {
            $task = $this->booking->book($this->conversation, $this->config, $this->owner, $start, $email, $reason, $direct);
        } catch (AppointmentBookingException $e) {
            return match ($e->reason) {
                AppointmentBookingException::SLOT_UNAVAILABLE => 'Ese horario ya no está disponible. Consultá disponibilidad de nuevo y ofrecé otras opciones.',
                AppointmentBookingException::HANDOFF => 'Un asesor tomó la conversación. No agendes; decile al cliente que un asesor lo va a atender.',
                AppointmentBookingException::ACTIVE_BOOKING_EXISTS => $this->activeBookingText($e->existing),
                default => self::AGENDA_UNAVAILABLE,
            };
        } catch (AppointmentAvailabilityException) {
            return self::AGENDA_UNAVAILABLE;
        }

        $this->lastBooking = $task;

        Log::info('AppointmentToolset: turno agendado', [
            'task_id' => $task->id,
            'conversation_id' => $this->conversation->id,
            'status' => $task->ai_booking_status?->value,
        ]);

        [$date, $time] = AppointmentBookingService::formatWhen($task);

        return $task->ai_booking_status === AiBookingStatus::CONFIRMED
            ? "Turno CONFIRMADO para el {$date} a las {$time} ({$tz}). Confirmale al cliente fecha y hora."
            : "Turno registrado como PENDIENTE de confirmación para el {$date} a las {$time}. Decile al cliente que un "
                .'asesor va a confirmar el horario a la brevedad; no le digas que está confirmado.';
    }

    private function activeBookingText(?Task $existing): string
    {
        if (! $existing) {
            return 'Esta conversación ya tiene un turno agendado. No agendes otro: si el cliente quiere cambiarlo, '
                .'decile que un asesor lo va a contactar.';
        }

        [$date, $time] = AppointmentBookingService::formatWhen($existing);
        $status = $existing->ai_booking_status === AiBookingStatus::CONFIRMED ? 'confirmado' : 'pendiente';

        return "Esta conversación ya tiene un turno {$status} para el {$date} a las {$time}. No agendes otro: si el "
            .'cliente quiere cambiarlo, decile que un asesor lo va a contactar.';
    }

    private function contactEmail(): ?string
    {
        if (! $this->conversation->contact_id) {
            return null;
        }

        $email = Contact::withoutGlobalScopes()->whereKey($this->conversation->contact_id)->value('email');

        return filled($email) ? $email : null;
    }

    /**
     * Parseo estricto: createFromFormat normaliza fechas imposibles
     * (2026-02-30 → 2026-03-02) con solo un warning; acá eso es un error.
     */
    private static function parseStrict(string $format, string $value, string $tz): ?CarbonImmutable
    {
        $value = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value, new \DateTimeZone($tz));
        $errors = \DateTimeImmutable::getLastErrors();

        if (! $parsed || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        if ($parsed->format($format) !== $value) {
            return null;
        }

        return CarbonImmutable::instance($parsed);
    }
}
