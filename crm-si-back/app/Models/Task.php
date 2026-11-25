<?php

namespace App\Models;

use App\Enums\AiBookingStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Task extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'conversation_id',
        'opportunity_id',
        'assigned_to',
        'name',
        'status',
        'priority',
        'type',
        'deadline',
        'description',
        'reminders',
        'recurrence',
        'depends_on',
        'checklist',
        'attachments',
        'synced_calendars',
        'completed_at',
        'starts_at',
        'ends_at',
        'meeting_timezone',
        'meeting_guest_email',
        'ai_booking_status',
        'ai_booking_decided_by',
        'ai_booking_decided_at',
        'ai_reschedule_starts_at',
        'ai_reschedule_ends_at',
        'ai_reschedule_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'priority' => TaskPriority::class,
            'type' => TaskType::class,
            'deadline' => 'datetime',
            'reminders' => 'array',
            'depends_on' => 'array',
            'checklist' => 'array',
            'attachments' => 'array',
            'synced_calendars' => 'array',
            'completed_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ai_booking_status' => AiBookingStatus::class,
            'ai_booking_decided_at' => 'datetime',
            'ai_reschedule_starts_at' => 'datetime',
            'ai_reschedule_ends_at' => 'datetime',
            'ai_reschedule_requested_at' => 'datetime',
        ];
    }

    /**
     * `starts_at`, `ends_at` y `deadline` son columnas timestamp sin zona
     * horaria. Los ISO enviados por el frontend representan instantes UTC, por
     * lo que antes de persistirlos hay que convertirlos a la hora de pared de
     * la aplicación. De lo contrario, 20:00Z se guarda como 20:00 local: el CRM
     * lo muestra tres horas después y Google Calendar lo recibe corrido.
     */
    public function setStartsAtAttribute(mixed $value): void
    {
        $this->attributes['starts_at'] = $this->appWallClock($value);
    }

    public function setEndsAtAttribute(mixed $value): void
    {
        $this->attributes['ends_at'] = $this->appWallClock($value);
    }

    public function setDeadlineAttribute(mixed $value): void
    {
        $this->attributes['deadline'] = $this->appWallClock($value);
    }

    private function appWallClock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)
            ->setTimezone((string) config('app.timezone'))
            ->format($this->getDateFormat());
    }

    /**
     * Si la tarea tiene un horario para publicar en el Google Calendar del
     * responsable: las reuniones como evento (inicio/fin), el resto de los
     * tipos como recordatorio en su `deadline`.
     */
    public function hasCalendarSlot(): bool
    {
        if (! $this->assigned_to) {
            return false;
        }

        return $this->type === TaskType::MEETING
            ? $this->starts_at !== null && $this->ends_at !== null
            : $this->deadline !== null;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function calendarSync(): HasOne
    {
        return $this->hasOne(TaskCalendarSync::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('tasks.view_any')) {
            return $query;
        }

        if (! $user->can('tasks.view_assigned')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('assigned_to', $user->id);
    }
}
