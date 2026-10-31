<?php

namespace App\Automation;

use App\Automation\Exceptions\ActionSkippedException;
use App\Automation\Exceptions\AmbiguousDeliveryException;
use App\Automation\Exceptions\RetryableActionException;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationAction;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\DB;

class AutomationEngine
{
    public function __construct(private AutomationRegistry $registry, private AutomationContext $context, private ConditionEvaluator $conditions) {}

    public function execute(AutomationRun $run): void
    {
        $run->load(['rule.actions', 'actionRuns']);
        $rule = $run->rule;
        if (! $rule || $rule->status !== AutomationRuleStatus::Active || $run->rule_version !== $rule->version) {
            $run->update(['status' => AutomationRunStatus::Cancelled, 'finished_at' => now(), 'error' => 'rule_inactive_or_version_changed']);

            return;
        }

        // Bulk update vía query builder: HasTimezoneAwareDates no aplica acá,
        // así que now() necesita ->utc() explícito (started_at ya en UTC no
        // se toca de nuevo).
        $claimed = AutomationRun::withoutGlobalScopes()
            ->whereKey($run->id)
            ->whereIn('status', [AutomationRunStatus::Scheduled, AutomationRunStatus::Queued])
            ->update([
                'status' => AutomationRunStatus::Running,
                'started_at' => $run->started_at ?? now()->utc(),
                'attempts' => $run->attempts + 1,
                'error' => null,
            ]);

        if (! $claimed) {
            $run->refresh();

            return;
        }

        $run->refresh();
        if (! $this->conditions->evaluate($rule->conditions, $this->context->forRun($run))) {
            $run->update(['status' => AutomationRunStatus::Skipped, 'finished_at' => now(), 'result' => ['reason' => 'conditions_not_met']]);

            return;
        }

        $results = [];
        foreach ($rule->actions as $action) {
            [$actionRun, $disposition] = $this->claimAction($run, $action);
            if ($disposition === 'succeeded') {
                $results[] = $actionRun->result;

                continue;
            }
            if ($disposition === 'needs_review') {
                $run->update([
                    'status' => AutomationRunStatus::NeedsReview,
                    'error' => 'delivery_outcome_unknown',
                    'finished_at' => now(),
                ]);

                return;
            }

            try {
                $result = $this->registry->action($action->type)->execute($action, $run);
                $actionRun->update([
                    'status' => AutomationRunStatus::Succeeded,
                    'result' => $result,
                    'delivery_confirmed_at' => now(),
                    'finished_at' => now(),
                ]);
                $results[] = $result;
            } catch (ActionSkippedException $exception) {
                $actionRun->update(['status' => AutomationRunStatus::Skipped, 'error' => $exception->getMessage(), 'finished_at' => now()]);
                $run->update(['status' => AutomationRunStatus::Skipped, 'error' => $exception->getMessage(), 'finished_at' => now()]);

                return;
            } catch (AmbiguousDeliveryException $exception) {
                $actionRun->update(['status' => AutomationRunStatus::NeedsReview, 'error' => $exception->getMessage(), 'finished_at' => now()]);
                $run->update(['status' => AutomationRunStatus::NeedsReview, 'error' => $exception->getMessage(), 'finished_at' => now()]);

                return;
            } catch (RetryableActionException $exception) {
                $actionRun->update(['status' => AutomationRunStatus::Failed, 'error' => $exception->getMessage(), 'finished_at' => now()]);
                $run->update(['status' => AutomationRunStatus::Queued, 'error' => $exception->getMessage()]);
                throw $exception;
            } catch (\Throwable $exception) {
                $actionRun->update(['status' => AutomationRunStatus::Failed, 'error' => $exception->getMessage(), 'finished_at' => now()]);
                $run->update(['status' => AutomationRunStatus::Failed, 'error' => $exception->getMessage(), 'finished_at' => now()]);

                return;
            }
        }

        $run->update(['status' => AutomationRunStatus::Succeeded, 'result' => ['actions' => $results], 'finished_at' => now()]);
    }

    /** @return array{AutomationActionRun, 'execute'|'succeeded'|'needs_review'} */
    private function claimAction(AutomationRun $run, AutomationAction $action): array
    {
        $deliveryKey = hash('sha256', "automation-action:{$run->deduplication_key}:{$action->id}");

        return DB::transaction(function () use ($run, $action, $deliveryKey): array {
            // Serializes first creation/claim even when two workers bypass the
            // queue-level lock or use different cache backends.
            AutomationRun::withoutGlobalScopes()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $lockedActionRun = AutomationActionRun::firstOrCreate(
                ['automation_run_id' => $run->id, 'automation_action_id' => $action->id],
                [
                    'position' => $action->position,
                    'status' => AutomationRunStatus::Queued,
                    'delivery_key' => $deliveryKey,
                    'input' => $action->config,
                ],
            );

            if ($lockedActionRun->status === AutomationRunStatus::Succeeded) {
                return [$lockedActionRun, 'succeeded'];
            }

            // A previous worker persisted the intent but never confirmed its
            // outcome. Re-sending could duplicate an externally accepted action.
            if ($lockedActionRun->status === AutomationRunStatus::Running && $lockedActionRun->delivery_started_at !== null) {
                $lockedActionRun->update([
                    'status' => AutomationRunStatus::NeedsReview,
                    'error' => 'delivery_outcome_unknown',
                    'finished_at' => now(),
                ]);

                return [$lockedActionRun->fresh(), 'needs_review'];
            }

            $lockedActionRun->update([
                'status' => AutomationRunStatus::Running,
                'attempts' => $lockedActionRun->attempts + 1,
                'delivery_key' => $lockedActionRun->delivery_key ?? $deliveryKey,
                'delivery_started_at' => now(),
                'delivery_confirmed_at' => null,
                'started_at' => now(),
                'finished_at' => null,
                'error' => null,
            ]);

            return [$lockedActionRun->fresh(), 'execute'];
        });
    }
}
