<?php

namespace App\Ai\Tools\Assistant;

use App\Ai\Tools\Assistant\Concerns\RespondsSafely;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Tareas pendientes del usuario (o de todo lo que puede ver), filtradas por
 * vencimiento.
 */
class ListTasks implements Tool
{
    use RespondsSafely;

    private const FILTERS = ['overdue', 'today', 'upcoming', 'pending'];

    private const LIMIT = 10;

    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'list_tasks';
    }

    public function description(): string
    {
        return 'Lista tareas pendientes (no hechas ni canceladas): vencidas, que vencen hoy, próximas o todas. '
            .'Por defecto solo las asignadas al usuario; con mine=false incluye todas las que puede ver.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()->enum(self::FILTERS)->required()
                ->description('overdue = vencidas, today = vencen hoy, upcoming = vencen más adelante, pending = todas las pendientes.'),
            'mine' => $schema->boolean()->description('true (por defecto) = solo las del usuario.'),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->respond(function () use ($request): array {
            $filter = in_array($request['filter'] ?? null, self::FILTERS, true) ? $request['filter'] : 'pending';
            $mine = ($request['mine'] ?? true) !== false;

            $query = Task::query()->visibleTo($this->user)
                ->whereNotIn('status', [TaskStatus::DONE, TaskStatus::CANCELED])
                ->when($mine, fn ($q) => $q->where('assigned_to', $this->user->id));

            match ($filter) {
                'overdue' => $query->where('deadline', '<', now()),
                'today' => $query->whereBetween('deadline', [today()->startOfDay(), today()->endOfDay()]),
                'upcoming' => $query->where('deadline', '>', now()),
                default => null,
            };

            return [
                'filter' => $filter,
                'total' => (clone $query)->count(),
                'tasks' => $query->with(['assignedUser:id,name', 'contact:id,name'])
                    ->orderBy('deadline')
                    ->limit(self::LIMIT)
                    ->get()
                    ->map(fn (Task $task) => [
                        'name' => $task->name,
                        'status' => $task->status?->value,
                        'priority' => $task->priority?->value,
                        'type' => $task->type?->value,
                        'deadline' => $task->deadline?->toDateTimeString(),
                        'assignee' => $task->assignedUser?->name,
                        'contact' => $task->contact?->name,
                    ])->all(),
            ];
        });
    }
}
