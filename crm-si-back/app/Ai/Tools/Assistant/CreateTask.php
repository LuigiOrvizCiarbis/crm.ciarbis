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
use Illuminate\Support\Facades\Cache;
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
 * en su zona horaria, detalle incluido), en su idioma, no el modelo. Un pedido
 * inválido no se le muestra para confirmar: vuelve al modelo como error para
 * que lo corrija o lo pregunte.
 */
class CreateTask implements Approvable, Tool
{
    use InteractsWithApprovals, RespondsSafely;

    private const DEADLINE_FORMAT = 'Y-m-d H:i';

    /** Se muestra completa en la confirmación, así que tiene que seguir siendo legible. */
    private const DESCRIPTION_MAX_LENGTH = 500;

    /** Una misma llamada (toolCallId) no crea dos tareas, p. ej. desde dos pestañas. */
    private const DEDUPE_TTL_SECONDS = 86400;

    /**
     * Textos del resumen a confirmar, por idioma del usuario.
     *
     * @var array<string, array<string, mixed>>
     */
    private const SUMMARY = [
        'es' => [
            'create' => 'Crear tarea «:name»',
            'due' => 'Vence: :date',
            'no_due' => 'Sin vencimiento',
            'contact' => 'Contacto: :name',
            'assignee' => 'Asignada a vos',
            'priority' => 'Prioridad: :value',
            'type' => 'Tipo: :value',
            'detail' => 'Detalle: :value',
            'priorities' => ['baja' => 'baja', 'media' => 'media', 'alta' => 'alta', 'critica' => 'crítica'],
            'types' => ['llamado' => 'Llamado', 'demo' => 'Demo', 'propuesta' => 'Propuesta', 'visita' => 'Visita', 'seguimiento' => 'Seguimiento', 'soporte' => 'Soporte'],
        ],
        'en' => [
            'create' => 'Create task «:name»',
            'due' => 'Due: :date',
            'no_due' => 'No due date',
            'contact' => 'Contact: :name',
            'assignee' => 'Assigned to you',
            'priority' => 'Priority: :value',
            'type' => 'Type: :value',
            'detail' => 'Details: :value',
            'priorities' => ['baja' => 'low', 'media' => 'medium', 'alta' => 'high', 'critica' => 'critical'],
            'types' => ['llamado' => 'Call', 'demo' => 'Demo', 'propuesta' => 'Proposal', 'visita' => 'Visit', 'seguimiento' => 'Follow-up', 'soporte' => 'Support'],
        ],
    ];

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
                ->description('Vencimiento futuro en la zona horaria del usuario, formato YYYY-MM-DD HH:MM. Omitilo si el usuario no dio fecha.'),
            'contact_id' => $schema->integer()->description('Id del contacto (de search_contacts). Opcional.'),
            'priority' => $schema->string()->enum(TaskPriority::values())->description('Por defecto media.'),
            'type' => $schema->string()->enum($this->allowedTypes())->description('Por defecto seguimiento.'),
            'description' => $schema->string()
                ->description('Detalle opcional, solo con lo que pidió el usuario. Máximo '.self::DESCRIPTION_MAX_LENGTH.' caracteres.'),
        ];
    }

    /**
     * Sin aprobación, handle() devuelve el error al modelo sin crear nada. La
     * herramienta se ofrece siempre (si desapareciera entre la pausa y la
     * confirmación, el SDK no podría reanudar el turno), así que el permiso se
     * revisa acá y en handle().
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        if (! $this->canCreate()) {
            return false;
        }

        $prepared = $this->prepare($request);

        return is_string($prepared) ? false : Approval::required($prepared['summary']);
    }

    public function handle(Request $request): string
    {
        return $this->respond(function () use ($request): array|string {
            if (! $this->canCreate()) {
                return 'El usuario no tiene permiso para crear tareas.';
            }

            $prepared = $this->prepare($request);
            if (is_string($prepared)) {
                return $prepared;
            }

            $callId = $request->toolCallId();
            if ($callId !== null && ! Cache::add("assistant:create_task:{$this->user->id}:{$callId}", true, self::DEDUPE_TTL_SECONDS)) {
                return 'Esta tarea ya se creó con esta confirmación.';
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

    private function canCreate(): bool
    {
        return $this->user->can('create', Task::class);
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
            // Mismo tope que muestra la confirmación: nada se guarda sin que el usuario lo vea.
            'description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
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
        if ($deadline?->isPast()) {
            return 'La fecha de vencimiento ya pasó. Si el usuario dijo "hoy" sin hora, usá hoy 23:59; '
                .'si dio una hora que ya pasó, pedile una fecha futura.';
        }

        $name = trim($data['name']);
        $description = isset($data['description']) ? trim($data['description']) : null;
        $priority = TaskPriority::from($data['priority'] ?? TaskPriority::MEDIUM->value);
        $type = TaskType::from($data['type'] ?? TaskType::FOLLOW_UP->value);
        $locale = $this->locale();
        $deadlineLabel = $deadline?->locale($locale)->isoFormat('ddd DD/MM/YYYY HH:mm');

        return [
            'attributes' => [
                'tenant_id' => $this->user->tenant_id,
                'name' => $name,
                'description' => $description ?: null,
                // ISO con offset: el modelo Task lo pasa a la hora de pared de la app.
                'deadline' => $deadline?->toIso8601String(),
                'priority' => $priority->value,
                'type' => $type->value,
                'assigned_to' => $this->user->id,
                'contact_id' => $contact?->id,
            ],
            'summary' => $this->summary($locale, $name, $deadlineLabel, $contact?->name, $priority, $type, $description),
            'deadline_label' => $deadlineLabel,
            'contact_name' => $contact?->name,
        ];
    }

    /**
     * Todo lo que se va a guardar aparece en el resumen, detalle completo
     * incluido: el modelo podría haber escrito ahí algo que el usuario no pidió
     * (p. ej. siguiendo instrucciones de un mensaje de un contacto).
     */
    private function summary(
        string $locale,
        string $name,
        ?string $deadlineLabel,
        ?string $contactName,
        TaskPriority $priority,
        TaskType $type,
        ?string $description,
    ): string {
        $text = self::SUMMARY[$locale];
        $line = fn (string $key, array $replace = []) => strtr($text[$key], $replace);

        return implode(' · ', array_filter([
            $line('create', [':name' => $name]),
            $deadlineLabel ? $line('due', [':date' => $deadlineLabel]) : $line('no_due'),
            $contactName ? $line('contact', [':name' => $contactName]) : null,
            $line('assignee'),
            $line('priority', [':value' => $text['priorities'][$priority->value]]),
            $line('type', [':value' => $text['types'][$type->value]]),
            $description ? $line('detail', [':value' => $description]) : null,
        ]));
    }

    private function locale(): string
    {
        $locale = $this->user->preferencesWithDefaults()['locale'] ?? 'es';

        return array_key_exists($locale, self::SUMMARY) ? $locale : 'es';
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
