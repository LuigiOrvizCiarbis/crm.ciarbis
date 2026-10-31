<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Notifications\DomainNotifier;
use Illuminate\Console\Command;

class SendTrialReminderNotifications extends Command
{
    protected $signature = 'notifications:trial-reminders';

    protected $description = 'Avisa a los owners de workspaces con el trial por vencer (3 y 1 día antes)';

    private const REMIND_AT_DAYS = [3, 1];

    public function handle(DomainNotifier $notifier): int
    {
        $sent = 0;

        Tenant::query()
            ->where('status', 'active')
            ->whereBetween('trial_ends_at', [now(), now()->addDays(max(self::REMIND_AT_DAYS))])
            ->each(function (Tenant $tenant) use ($notifier, &$sent) {
                $daysLeft = $tenant->trialDaysLeft();
                if ($daysLeft !== null && in_array($daysLeft, self::REMIND_AT_DAYS, true)) {
                    $notifier->trialExpiring($tenant, $daysLeft);
                    $sent++;
                }
            });

        $this->info("Recordatorios de trial enviados: {$sent}");

        return self::SUCCESS;
    }
}
