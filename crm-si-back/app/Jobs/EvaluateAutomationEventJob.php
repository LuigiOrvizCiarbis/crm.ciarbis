<?php

namespace App\Jobs;

use App\Automation\AutomationRegistry;
use App\Automation\DateAutomationScheduler;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationRule;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateAutomationEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    public function __construct(public int $tenantId, public array $event) {}

    public function handle(AutomationRegistry $registry, DateAutomationScheduler $dateScheduler): void
    {
        $this->rescheduleDateRules($dateScheduler);

        AutomationRule::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', AutomationRuleStatus::Active)
            ->where('trigger_type', $this->event['type'])
            ->with('actions')
            ->each(function (AutomationRule $rule) use ($registry): void {
                if (! $registry->trigger($rule->trigger_type)->matches($rule, $this->event)) {
                    return;
                }
                $eventId = (string) $this->event['id'];
                $dedup = hash('sha256', implode('|', [$rule->id, $rule->version, $this->event['subject_type'], $this->event['subject_id'], $eventId, 0]));
                $run = $rule->runs()->firstOrCreate(['deduplication_key' => $dedup], [
                    'tenant_id' => $rule->tenant_id,
                    'rule_version' => $rule->version,
                    'status' => AutomationRunStatus::Queued,
                    'subject_type' => $this->event['subject_type'],
                    'subject_id' => $this->event['subject_id'],
                    'event_id' => $eventId,
                    'context' => $this->event,
                    'queued_at' => now(),
                ]);
                if ($run->wasRecentlyCreated) {
                    ExecuteAutomationRunJob::dispatch($run->id);
                }
            });
    }

    private function rescheduleDateRules(DateAutomationScheduler $scheduler): void
    {
        if (! in_array($this->event['type'] ?? null, ['contact.field_changed', 'conversation.stage_changed', 'conversation.status_changed'], true)) {
            return;
        }

        AutomationRule::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', AutomationRuleStatus::Active)
            ->where('trigger_type', 'date.reached')
            ->get()
            ->each(function (AutomationRule $rule) use ($scheduler): void {
                if (($rule->trigger_config['subject'] ?? null) !== ($this->event['subject_type'] ?? null)) {
                    return;
                }
                $field = preg_replace('/^(contact|conversation)\./', '', (string) ($rule->trigger_config['field'] ?? ''));
                $changed = $this->event['changed_fields'] ?? [];
                if (! in_array($field, $changed, true) && ! (str_starts_with($field, 'custom_data.') && in_array('custom_data', $changed, true))) {
                    return;
                }

                // Bulk update: HasTimezoneAwareDates no aplica, ->utc() explícito.
                $rule->runs()->where('subject_type', $this->event['subject_type'])->where('subject_id', $this->event['subject_id'])
                    ->whereIn('status', [AutomationRunStatus::Scheduled, AutomationRunStatus::Queued])
                    ->update(['status' => AutomationRunStatus::Cancelled, 'finished_at' => now()->utc(), 'error' => 'source_date_changed']);
                $model = $this->event['subject_type'] === 'contact' ? Contact::class : Conversation::class;
                $subject = $model::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->event['subject_id']);
                if ($subject) {
                    $scheduler->scheduleSubject($rule, $subject);
                }
            });
    }
}
