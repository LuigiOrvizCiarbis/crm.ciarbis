<?php

namespace App\Ai\Tools\Assistant;

use App\Ai\Tools\Assistant\Concerns\RespondsSafely;
use App\Enums\TaskPriority;
use App\Enums\TaskType;
use App\Models\Contact;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\TaskCreator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Crea una tarea asignada al usuario, con su confirmación previa.
 *
 * Todo se valida y resuelve antes de pedir la confirmación: el texto que ve el
 * usuario lo arma el servidor con los datos reales (nombre del contacto, fecha
 * en su zona horaria), no el modelo. Un pedido inválido no se le muestra para
 * confirmar: vuelve al modelo como error para que lo corrija o lo pregunte.
 */
class CreateTask implements Approvable, Tool
{
    use InteractsWithApprovals, RespondsSafely;

    private const DEADLINE_FORMAT = 'Y-m-d H:i';

    public function __construct(private User $user, private TaskCreator $creator) {}

    public function name(): string
    {
        return 'create_task';
    }

    public function description(): string
    {
        return 'Crea una tarea asignada al usuario. El usuario la confirma antes de que se cree. '
            .'Para vincular un contacto, buscalo antes con search_contacts y pasá su id.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Título corto de la tarea, p. ej. "Llamar a Jorge Merlo".'),
            'deadline' => $schema->string()
                ->description('Vencimiento en la zona horaria del usuario, formato YYYY-MM-DD HH:MM. Omitilo si el usuario no dio fecha.'),
            'contact_id' => $schema->integer()->description('Id del contacto (de search_contacts). Opcional.'),
            'priority' => $schema->string()->enum(TaskPriority::values())->description('Por defecto media.'),
            'type' => $schema->string()->enum($this->allowedTypes())->description('Por defecto seguimiento.'),
            'description' => $schema->string()->description('Detalle opcional.'),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $prepared = $this->prepare($request);

        // Sin aprobación, handle() devuelve el error al modelo sin crear nada.
        return is_string($prepared) ? false : Approval::required($prepared['summary']);
    }

    public function handle(Request $request): string
    {
        return $this->respond(function () use ($request): array|string {
            if (! $this->user->can('create', Task::class)) {
                return 'No tenés permiso para crear tareas.';
            }

            $prepared = $this->prepare($request);
            if (is_string($prepared)) {
                return $prepared;
            }

            $task = $this->creator->create($prepared['attributes'], $this->user);

            return [
                'created' => true,
                'task' => [
                    'id' => $task->id,
                    'name' => $task->name,
                    'deadline' => $prepared['deadline_label'],
                    'contact' => $prepared['contact_name'],
                    'assignee' => $this->user->name,
                ],
                'url' => '/tareas',
            ];
        });
    }

    /**
     * Valida el pedido y lo convierte en atributos de Task más el resumen a
     * confirmar. Devuelve el error como texto si el pedido no es válido.
     *
     * @return array{attributes: array<string, mixed>, summary: string, deadline_label: ?string, contact_name: ?string}|string
     */
    private function prepare(Request $request): array|string
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'deadline' => ['nullable', 'date_format:'.self::DEADLINE_FORMAT],
            'contact_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'type' => ['nullable', Rule::in($this->allowedTypes())],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return 'Datos inválidos: '.implode(' ', $validator->errors()->all());
        }

        $data = $validator->validated();
        $contact = null;
        if (isset($data['contact_id'])) {
            $contact = Contact::query()->visibleTo($this->user)->find($data['contact_id']);
            if (! $contact) {
                return 'No encontré ese contacto entre los que puede ver el usuario. Buscalo con search_contacts.';
            }
        }

        $deadline = isset($data['deadline'])
            ? CarbonImmutable::createFromFormat(self::DEADLINE_FORMAT, $data['deadline'], $this->user->preferredTimezone())
            : null;
        $priority = TaskPriority::from($data['priority'] ?? TaskPriority::MEDIUM->value);
        $type = TaskType::from($data['type'] ?? TaskType::FOLLOW_UP->value);
        $deadlineLabel = $deadline?->locale('es')->isoFormat('ddd DD/MM HH:mm');

        return [
            'attributes' => [
                'tenant_id' => $this->user->tenant_id,
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                // ISO con offset: el modelo Task lo pasa a la hora de pared de la app.
                'deadline' => $deadline?->toIso8601String(),
                'priority' => $priority->value,
                'type' => $type->value,
                'assigned_to' => $this->user->id,
                'contact_id' => $contact?->id,
            ],
            'summary' => implode(' · ', array_filter([
                'Crear tarea «'.trim($data['name']).'»',
                $deadlineLabel ? "Vence: {$deadlineLabel}" : 'Sin vencimiento',
                $contact ? "Contacto: {$contact->name}" : null,
                'Asignada a vos',
                "Prioridad: {$priority->value}",
                "Tipo: {$type->label()}",
            ])),
            'deadline_label' => $deadlineLabel,
            'contact_name' => $contact?->name,
        ];
    }

    /**
     * Las reuniones exigen horario de inicio/fin, zona y calendario: quedan
     * fuera de este alta rápida.
     *
     * @return list<string>
     */
    private function allowedTypes(): array
    {
        return array_values(array_diff(TaskType::values(), [TaskType::MEETING->value]));
    }
}
