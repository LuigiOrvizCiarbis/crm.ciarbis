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
        ];
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
