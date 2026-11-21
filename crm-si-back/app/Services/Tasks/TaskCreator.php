<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Jobs\SyncTaskCalendarEventJob;
use App\Models\Task;
use App\Models\User;
use App\Services\Notifications\DomainNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Alta de una tarea con los mismos efectos que la pantalla de tareas: valores
 * por defecto, aviso al responsable y evento de calendario si corresponde.
 * La usan la API de tareas y el asistente interno.
 */
class TaskCreator
{
    private const DEFAULTS = [
        'status' => TaskStatus::NEW->value,
        'priority' => TaskPriority::MEDIUM->value,
        'type' => TaskType::FOLLOW_UP->value,
        'reminders' => [],
        'depends_on' => [],
        'checklist' => [],
        'attachments' => [],
        'synced_calendars' => [],
    ];

    public function __construct(private DomainNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $attributes  Atributos ya validados.
     */
    public function create(array $attributes, User $actor): Task
    {
        $task = Task::create([...self::DEFAULTS, ...$attributes]);
        $this->notifier->taskAssigned($task, $actor);

        // Una tarea nueva sin responsable u horario no tiene evento que crear
        // ni que retirar: no vale el viaje a la cola.
        if ($task->status !== TaskStatus::CANCELED && $task->hasCalendarSlot()) {
            DB::afterCommit(fn () => SyncTaskCalendarEventJob::dispatch($task->id, 'upsert'));
        }

        return $task;
    }
}
