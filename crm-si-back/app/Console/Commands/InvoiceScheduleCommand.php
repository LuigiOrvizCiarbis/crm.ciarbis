<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceRecurrence;
use App\Models\InvoiceSetting;
use App\Jobs\SendInvoiceReminderJob;
use App\Services\InvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceScheduleCommand extends Command
{
    protected $signature = 'invoices:dispatch-due';
    protected $description = 'Emite invoices programados y crea cobros recurrentes vencidos';

    public function handle(InvoiceService $service): int
    {
        foreach (InvoiceSetting::where('enabled', true)->get() as $settings) {
            $now = CarbonImmutable::now($settings->timezone);
            if ($now->hour < $settings->send_hour) continue;
            Invoice::where('tenant_id', $settings->tenant_id)->where('status', 'issued')->where('delivery_status', 'delivered')->where('next_reminder_at', '<=', $now->utc())->orderBy('id')->chunkById(100, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $claimed = Invoice::whereKey($invoice->id)->whereNotNull('next_reminder_at')->update(['next_reminder_at' => null]);
                    if ($claimed) SendInvoiceReminderJob::dispatch($invoice->id, $invoice->tenant_id);
                }
            });
            Invoice::where('tenant_id', $settings->tenant_id)->where('status', 'scheduled')->where('scheduled_at', '<=', $now->utc())->orderBy('id')->chunkById(100, function ($invoices) use ($service): void {
                foreach ($invoices as $invoice) $service->issue($invoice);
            });

            InvoiceRecurrence::where('tenant_id', $settings->tenant_id)->where('status', 'active')->whereDate('next_occurrence_on', '<=', $now->toDateString())->orderBy('id')->chunkById(100, function ($recurrences) use ($settings, $now, $service): void {
                foreach ($recurrences as $recurrence) {
                    DB::transaction(function () use ($recurrence, $settings, $now, $service): void {
                        $recurrence = InvoiceRecurrence::whereKey($recurrence->id)->lockForUpdate()->first();
                        if (! $recurrence || $recurrence->status !== 'active' || ! $recurrence->next_occurrence_on) return;
                        $scheduled = CarbonImmutable::parse((string) $recurrence->next_occurrence_on, $settings->timezone);
                        if ($scheduled->gt($now)) return;
                        if ($recurrence->ends_on && $scheduled->gt(CarbonImmutable::parse((string) $recurrence->ends_on))) {
                            $recurrence->update(['status' => 'completed', 'next_occurrence_on' => null]); return;
                        }
                        $missed = $scheduled->toDateString() < $now->toDateString();
                        $invoice = Invoice::firstOrCreate(
                            ['invoice_recurrence_id' => $recurrence->id, 'issued_on' => $scheduled->toDateString()],
                            ['tenant_id' => $recurrence->tenant_id, 'contact_id' => $recurrence->contact_id, 'number' => 'pending', 'concept' => $recurrence->concept, 'amount_cents' => $recurrence->amount_cents, 'currency' => 'ARS', 'status' => $missed ? 'draft' : 'scheduled', 'issued_on' => $scheduled->toDateString(), 'scheduled_at' => $scheduled->startOfDay()->utc()],
                        );
                        if ($invoice->number === 'pending') $invoice->update(['number' => 'INV-'.str_pad((string) $invoice->id, 8, '0', STR_PAD_LEFT)]);
                        if ($missed) {
                            \App\Models\InvoiceEvent::create(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'type' => 'missed_occurrence_review', 'details' => ['scheduled_on' => $scheduled->toDateString()]]);
                        } else {
                            try {
                                $service->issue($invoice);
                                \App\Models\InvoiceEvent::create(['tenant_id' => $invoice->tenant_id, 'invoice_id' => $invoice->id, 'type' => 'recurrence_generated', 'details' => ['recurrence_id' => $recurrence->id, 'scheduled_on' => $scheduled->toDateString()]]);
                            } catch (\Throwable $exception) {
                                // Keep a reviewable draft and advance this recurrence so it cannot block later work.
                                $invoice->update(['status' => 'draft', 'scheduled_at' => null]);
                                \App\Models\InvoiceEvent::create([
                                    'tenant_id' => $invoice->tenant_id,
                                    'invoice_id' => $invoice->id,
                                    'type' => 'recurrence_issue_failed',
                                    'details' => [
                                        'recurrence_id' => $recurrence->id,
                                        'scheduled_on' => $scheduled->toDateString(),
                                        'error' => mb_substr($exception->getMessage(), 0, 500),
                                    ],
                                ]);
                                Log::warning('Recurring invoice could not be issued; occurrence left as draft.', [
                                    'recurrence_id' => $recurrence->id,
                                    'invoice_id' => $invoice->id,
                                    'tenant_id' => $invoice->tenant_id,
                                    'error' => $exception->getMessage(),
                                ]);
                            }
                        }
                        $period = $recurrence->invoices()->count();
                        $next = $this->nextFromAnchor(CarbonImmutable::parse((string) $recurrence->starts_on, $settings->timezone), $recurrence->interval_unit, $recurrence->interval_count, $period);
                        $recurrence->update(['next_occurrence_on' => $recurrence->ends_on && $next->gt(CarbonImmutable::parse((string) $recurrence->ends_on)) ? null : $next->toDateString(), 'status' => $recurrence->ends_on && $next->gt(CarbonImmutable::parse((string) $recurrence->ends_on)) ? 'completed' : 'active']);
                    });
                }
            });
        }
        return self::SUCCESS;
    }

    private function nextFromAnchor(CarbonImmutable $anchor, string $unit, int $count, int $period): CarbonImmutable
    {
        $total = $count * $period;
        return match ($unit) {
            'days' => $anchor->addDays($total), 'weeks' => $anchor->addWeeks($total),
            'years' => CarbonImmutable::create($anchor->year + $total, $anchor->month, min($anchor->day, CarbonImmutable::create($anchor->year + $total, $anchor->month, 1)->daysInMonth), 0, 0, 0, $anchor->timezone),
            default => $this->monthFromAnchor($anchor, $total),
        };
    }

    private function monthFromAnchor(CarbonImmutable $anchor, int $months): CarbonImmutable
    {
        $month = $anchor->startOfMonth()->addMonthsNoOverflow($months);
        return $month->setDay(min($anchor->day, $month->daysInMonth));
    }
}
