<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\WhatsAppTemplate;
use App\Services\BillingProvisioner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Deja un tenant listo para el módulo de cobranzas en un paso: campos custom
 * (vencimiento, estado, contador de mora), la BillingConfig apuntando a esas
 * keys, y las AutomationRule de recordatorio/reclamo (más la de trial, si se
 * pasa esa plantilla).
 *
 * Las plantillas de WhatsApp NO se crean acá — se aprueban en Meta. El
 * comando exige el id de una plantilla ya aprobada por cada regla que
 * provisiona. Para pedirlas desde la UI (con textos editables y provisioning
 * automático al aprobarse) está el asistente de /configuracion, que usa el
 * mismo BillingProvisioner.
 */
class BillingProvisionCommand extends Command
{
    protected $signature = 'billing:provision
                            {tenant : ID del tenant}
                            {--reminder-template= : ID de la plantilla aprobada para el aviso previo al vencimiento}
                            {--overdue-template= : ID de la plantilla aprobada para el reclamo posterior al vencimiento}
                            {--trial-template= : ID de la plantilla aprobada para el aviso de fin de prueba (opcional)}
                            {--due-date-field=vencimiento : Key del campo custom de vencimiento}
                            {--status-field=estado : Key del campo custom de estado}
                            {--overdue-cycles-field=ciclos_impagos : Key del campo custom de ciclos impagos}
                            {--reminder-days=3 : Días antes del vencimiento para el aviso previo}
                            {--overdue-days=2 : Días después del vencimiento para el reclamo}
                            {--grace-days=5 : Días de gracia del roll-cycle antes de avanzar el ciclo}
                            {--timezone=America/Argentina/Buenos_Aires : Timezone del módulo}';

    protected $description = 'Provisiona el módulo de cobranzas para un tenant: campos, config y reglas de automatización';

    public function handle(BillingProvisioner $provisioner): int
    {
        $tenant = Tenant::find((int) $this->argument('tenant'));
        if (! $tenant) {
            $this->error("Tenant #{$this->argument('tenant')} no encontrado.");

            return self::FAILURE;
        }

        $reminderTemplateId = $this->option('reminder-template');
        $overdueTemplateId = $this->option('overdue-template');
        if (! $reminderTemplateId || ! $overdueTemplateId) {
            $this->error('--reminder-template y --overdue-template son obligatorios.');

            return self::FAILURE;
        }

        $templates = [];

        $reminder = $this->resolveApprovedTemplate($tenant, (int) $reminderTemplateId, 'aviso previo');
        $overdue = $this->resolveApprovedTemplate($tenant, (int) $overdueTemplateId, 'reclamo');
        if (! $reminder || ! $overdue) {
            return self::FAILURE;
        }
        $templates['reminder'] = $reminder;
        $templates['overdue'] = $overdue;

        if ($trialTemplateId = $this->option('trial-template')) {
            $trial = $this->resolveApprovedTemplate($tenant, (int) $trialTemplateId, 'aviso de trial');
            if (! $trial) {
                return self::FAILURE;
            }
            $templates['trial'] = $trial;
        }

        try {
            $result = $provisioner->provision($tenant, $templates, [
                'due_date_field' => (string) $this->option('due-date-field'),
                'status_field' => (string) $this->option('status-field'),
                'overdue_cycles_field' => (string) $this->option('overdue-cycles-field'),
                'timezone' => (string) $this->option('timezone'),
                'grace_days' => (int) $this->option('grace-days'),
                'reminder_days' => (int) $this->option('reminder-days'),
                'overdue_days' => (int) $this->option('overdue-days'),
                // Corrido a mano por un operador que sabe lo que hace: las
                // reglas quedan activas. El flujo automático del webhook las
                // deja en Draft, porque ahí nadie está mirando.
                'activate_rules' => true,
            ]);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $config = $result['config'];

        $this->info("Tenant #{$tenant->id} ({$tenant->name}) provisionado para cobranzas.");
        $this->line("Campos: {$config->due_date_field_key}, {$config->status_field_key}, {$config->overdue_cycles_field_key}");
        $this->line("grace_days={$config->grace_days} (> overdue-days={$this->option('overdue-days')})");

        foreach ($result['rules'] as $ruleName) {
            $this->line("Regla creada y activada: «{$ruleName}».");
        }

        if ($result['rules'] === []) {
            $this->line('Las reglas ya existían — sin cambios.');
        }

        return self::SUCCESS;
    }

    private function resolveApprovedTemplate(Tenant $tenant, int $templateId, string $label): ?WhatsAppTemplate
    {
        $template = WhatsAppTemplate::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->find($templateId);

        if (! $template) {
            $this->error("Plantilla #{$templateId} ({$label}) no existe en este tenant.");

            return null;
        }

        if (! $template->isApproved()) {
            $this->error("Plantilla «{$template->name}» ({$label}) no está aprobada por Meta todavía.");

            return null;
        }

        return $template;
    }
}
