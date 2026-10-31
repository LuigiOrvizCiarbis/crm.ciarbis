<?php

namespace App\Automation;

use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutomationRuleService
{
    public function __construct(private AutomationRegistry $registry, private DateAutomationScheduler $dateScheduler) {}

    public function create(array $payload, User $user): AutomationRule
    {
        $this->validateHandlers($payload, (int) $user->tenant_id);

        return DB::transaction(function () use ($payload, $user): AutomationRule {
            $rule = AutomationRule::create([
                'tenant_id' => $user->tenant_id,
                'created_by' => $user->id,
                'name' => $payload['name'],
                'status' => AutomationRuleStatus::Draft,
                'trigger_type' => $payload['trigger_type'],
                'trigger_config' => $payload['trigger_config'] ?? [],
                'conditions' => $payload['conditions'] ?? null,
                'timezone' => $payload['timezone'] ?? $user->tenant?->timezone ?? 'UTC',
            ]);
            $this->syncActions($rule, $payload['actions']);

            return $rule->load('actions');
        });
    }

    public function update(AutomationRule $rule, array $payload): AutomationRule
    {
        $merged = [
            'trigger_type' => $payload['trigger_type'] ?? $rule->trigger_type,
            'trigger_config' => $payload['trigger_config'] ?? $rule->trigger_config,
            'conditions' => array_key_exists('conditions', $payload) ? $payload['conditions'] : $rule->conditions,
            'actions' => $payload['actions'] ?? $rule->actions->map->only(['type', 'config'])->all(),
        ];
        $this->validateHandlers($merged, (int) $rule->tenant_id);

        $wasActive = $rule->status === AutomationRuleStatus::Active;
        $updated = DB::transaction(function () use ($rule, $payload): AutomationRule {
            $rule->update([
                'name' => $payload['name'] ?? $rule->name,
                'trigger_type' => $payload['trigger_type'] ?? $rule->trigger_type,
                'trigger_config' => $payload['trigger_config'] ?? $rule->trigger_config,
                'conditions' => array_key_exists('conditions', $payload) ? $payload['conditions'] : $rule->conditions,
                'timezone' => $payload['timezone'] ?? $rule->timezone,
                'version' => $rule->version + 1,
            ]);
            if (isset($payload['actions'])) {
                $this->syncActions($rule, $payload['actions']);
            }
            // Bulk update: HasTimezoneAwareDates no aplica, ->utc() explícito.
            $rule->runs()->whereIn('status', [AutomationRunStatus::Scheduled, AutomationRunStatus::Queued])->update([
                'status' => AutomationRunStatus::Cancelled,
                'finished_at' => now()->utc(),
                'error' => 'rule_version_changed',
            ]);

            return $rule->fresh('actions');
        });

        if ($wasActive && $updated->trigger_type === 'date.reached') {
            $this->dateScheduler->scheduleRule($updated);
        }

        return $updated;
    }

    public function activate(AutomationRule $rule): AutomationRule
    {
        $this->validateHandlers(['trigger_type' => $rule->trigger_type, 'trigger_config' => $rule->trigger_config, 'actions' => $rule->actions->map->only(['type', 'config'])->all()], (int) $rule->tenant_id);
        $rule->update(['status' => AutomationRuleStatus::Active, 'activated_at' => now()]);
        if ($rule->trigger_type === 'date.reached') {
            // scheduleRule() dedupes by (rule, version, subject, target date), so a
            // run cancelled by a previous pause() is never regenerated — it must be
            // explicitly revived here before scheduling can fill in anything new.
            $this->reviveCancelledPauseRuns($rule);
            $this->dateScheduler->scheduleRule($rule);
        }

        return $rule->fresh('actions');
    }

    private function reviveCancelledPauseRuns(AutomationRule $rule): void
    {
        // scheduled_for es timestamptz: comparar en UTC. Ver HasTimezoneAwareDates.
        $rule->runs()
            ->where('status', AutomationRunStatus::Cancelled)
            ->where('error', 'rule_paused')
            ->where('rule_version', $rule->version)
            ->where('scheduled_for', '>', now()->utc())
            ->update([
                'status' => AutomationRunStatus::Scheduled,
                'finished_at' => null,
                'error' => null,
            ]);
    }

    public function pause(AutomationRule $rule): AutomationRule
    {
        $rule->update(['status' => AutomationRuleStatus::Paused]);
        // Bulk update sobre la relación: no pasa por el modelo, así que
        // HasTimezoneAwareDates no aplica. ->utc() explícito.
        $rule->runs()->whereIn('status', [AutomationRunStatus::Scheduled, AutomationRunStatus::Queued])->update(['status' => AutomationRunStatus::Cancelled, 'finished_at' => now()->utc(), 'error' => 'rule_paused']);

        return $rule->fresh('actions');
    }

    private function validateHandlers(array $payload, int $tenantId): void
    {
        $errors = [];
        try {
            $errors = $this->registry->trigger((string) ($payload['trigger_type'] ?? ''))->validate($payload['trigger_config'] ?? []);
            foreach ($payload['actions'] ?? [] as $index => $action) {
                foreach ($this->registry->action((string) ($action['type'] ?? ''))->validate($action['config'] ?? [], $tenantId) as $field => $messages) {
                    $errors["actions.{$index}.{$field}"] = $messages;
                }
            }
        } catch (\InvalidArgumentException $exception) {
            $errors['type'][] = $exception->getMessage();
        }
        if (empty($payload['actions'])) {
            $errors['actions'][] = 'Agregá al menos una acción.';
        }
        $this->validateConditions($payload['conditions'] ?? null, $errors);
        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function syncActions(AutomationRule $rule, array $actions): void
    {
        $rule->actions()->delete();
        foreach (array_values($actions) as $position => $action) {
            $rule->actions()->create(['position' => $position, 'type' => $action['type'], 'config' => $action['config'] ?? []]);
        }
    }

    private function validateConditions(?array $node, array &$errors, string $path = 'conditions', int $depth = 0): void
    {
        if ($node === null || $node === []) {
            return;
        }
        if ($depth > 5) {
            $errors[$path][] = 'El árbol de condiciones no puede superar cinco niveles.';

            return;
        }
        if (array_key_exists('conditions', $node)) {
            if (! in_array(strtoupper((string) ($node['operator'] ?? '')), ['AND', 'OR'], true)) {
                $errors["{$path}.operator"][] = 'El grupo debe usar AND u OR.';
            }
            if (! is_array($node['conditions'])) {
                $errors["{$path}.conditions"][] = 'Las condiciones del grupo deben ser una lista.';

                return;
            }
            foreach ($node['conditions'] as $index => $child) {
                if (! is_array($child)) {
                    $errors["{$path}.conditions.{$index}"][] = 'La condición no es válida.';

                    continue;
                }
                $this->validateConditions($child, $errors, "{$path}.conditions.{$index}", $depth + 1);
            }

            return;
        }

        $field = (string) ($node['field'] ?? '');
        if (! str_starts_with($field, 'contact.') && ! str_starts_with($field, 'conversation.')
            && ! str_starts_with($field, 'old.') && ! str_starts_with($field, 'new.') && ! str_starts_with($field, 'event.')) {
            $errors["{$path}.field"][] = 'La ruta debe pertenecer al contacto, conversación o contexto del evento.';
        }
        $operators = ['equals', 'not_equals', 'greater_than', 'greater_or_equal', 'less_than', 'less_or_equal', 'empty', 'not_empty', 'contains', 'in', 'has_tag', 'stage_is'];
        if (! in_array($node['operator'] ?? null, $operators, true)) {
            $errors["{$path}.operator"][] = 'El operador no está permitido.';
        }
    }
}
