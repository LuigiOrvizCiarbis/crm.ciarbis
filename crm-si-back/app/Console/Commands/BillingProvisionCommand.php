<?php

namespace App\Console\Commands;

use App\Automation\AutomationRuleService;
use App\Enums\ContactFieldType;
use App\Models\AutomationRule;
use App\Models\BillingConfig;
use App\Models\Channel;
use App\Models\ContactField;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deja un tenant listo para el módulo de cobranzas en un paso: campos custom
 * (vencimiento, estado, contador de mora), la BillingConfig apuntando a esas
 * keys, y las AutomationRule de recordatorio/reclamo (más la de trial, si se
 * pasa esa plantilla).
 *
 * Las plantillas de WhatsApp NO se crean acá — se aprueban en Meta. El
 * comando exige el id de una plantilla ya aprobada por cada regla que
 * provisiona, y arma los parámetros del body con
 * WhatsAppTemplate::expectedBodyParameters(), mapeando cada uno al
 * vencimiento por defecto (el dato que casi cualquier plantilla de cobranza
 * necesita); si eso no alcanza, se ajusta después desde /automations.
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
                            {--grace-days=5 : Días de gracia del roll-cycle antes de avanzar el ciclo (Fase 5)}
                            {--timezone=America/Argentina/Buenos_Aires : Timezone del módulo}';

    protected $description = 'Provisiona el módulo de cobranzas para un tenant: campos, config y reglas de automatización';

    private AutomationRuleService $rules;

    public function handle(AutomationRuleService $rules, PermissionRegistrar $registrar): int
    {
        $this->rules = $rules;

        $tenant = Tenant::find((int) $this->argument('tenant'));
        if (! $tenant) {
            $this->error("Tenant #{$this->argument('tenant')} no encontrado.");

            return self::FAILURE;
        }

        // Los roles de Spatie están scopeados por team: sin esto, resolveOwner()
        // filtra contra el team que haya quedado activo del último request/job
        // que corrió en este proceso, no contra este tenant (mismo patrón que
        // ResyncSystemRoles::L48 y MigrateUsersToSpatieRoles::L58).
        $registrar->setPermissionsTeamId($tenant->id);
        $registrar->forgetCachedPermissions();

        $graceDays = (int) $this->option('grace-days');
        $overdueDays = (int) $this->option('overdue-days');
        if ($graceDays <= $overdueDays) {
            $this->error(
                "grace-days ({$graceDays}) debe ser mayor que overdue-days ({$overdueDays}): si no, ".
                'billing:roll-cycle (Fase 5) cancela el reclamo antes de que salga.'
            );

            return self::FAILURE;
        }

        $reminderTemplateId = $this->option('reminder-template');
        $overdueTemplateId = $this->option('overdue-template');
        if (! $reminderTemplateId || ! $overdueTemplateId) {
            $this->error('--reminder-template y --overdue-template son obligatorios.');

            return self::FAILURE;
        }

        $reminderTemplate = $this->resolveApprovedTemplate($tenant, (int) $reminderTemplateId, 'aviso previo');
        $overdueTemplate = $this->resolveApprovedTemplate($tenant, (int) $overdueTemplateId, 'reclamo');
        if (! $reminderTemplate || ! $overdueTemplate) {
            return self::FAILURE;
        }

        $trialTemplateOption = $this->option('trial-template');
        $trialTemplate = null;
        if ($trialTemplateOption) {
            $trialTemplate = $this->resolveApprovedTemplate($tenant, (int) $trialTemplateOption, 'aviso de trial');
            if (! $trialTemplate) {
                return self::FAILURE;
            }
        }

        $owner = $this->resolveOwner($tenant);
        if (! $owner) {
            $this->error("El tenant #{$tenant->id} no tiene un usuario Owner resoluble.");

            return self::FAILURE;
        }

        $dueDateKey = (string) $this->option('due-date-field');
        $statusKey = (string) $this->option('status-field');
        $overdueCyclesKey = (string) $this->option('overdue-cycles-field');
        $timezone = (string) $this->option('timezone');

        $dueDateField = ContactField::firstOrCreate(
            ['tenant_id' => $tenant->id, 'key' => $dueDateKey],
            ['label' => 'Vencimiento', 'type' => ContactFieldType::Date, 'display_order' => 900],
        );
        $statusField = ContactField::firstOrCreate(
            ['tenant_id' => $tenant->id, 'key' => $statusKey],
            [
                'label' => 'Estado de pago',
                'type' => ContactFieldType::Select,
                'options' => ['choices' => BillingConfig::STATUSES],
                'display_order' => 901,
            ],
        );
        // El campo puede haber sido creado antes sin alguna de las tres
        // choices que el motor necesita (ver BillingConfigRequest): se
        // completan en vez de fallar, así una corrida sobre un tenant con
        // campos parcialmente armados a mano converge igual.
        $existingChoices = is_array($statusField->options['choices'] ?? null) ? $statusField->options['choices'] : [];
        $missingChoices = array_diff(BillingConfig::STATUSES, $existingChoices);
        if ($missingChoices !== []) {
            $statusField->update(['options' => ['choices' => array_values([...$existingChoices, ...$missingChoices])]]);
        }
        $overdueCyclesField = ContactField::firstOrCreate(
            ['tenant_id' => $tenant->id, 'key' => $overdueCyclesKey],
            ['label' => 'Ciclos impagos', 'type' => ContactFieldType::Number, 'display_order' => 902],
        );

        $billingConfig = BillingConfig::firstOrNew(['tenant_id' => $tenant->id]);
        $billingConfig->tenant_id = $tenant->id;
        $billingConfig->due_date_field_key = $dueDateField->key;
        $billingConfig->status_field_key = $statusField->key;
        $billingConfig->overdue_cycles_field_key = $overdueCyclesField->key;
        $billingConfig->cycle_unit = 'months';
        $billingConfig->cycle_length = 1;
        $billingConfig->timezone = $timezone;
        $billingConfig->grace_days = $graceDays;
        $billingConfig->enabled = true;
        $billingConfig->save();

        try {
            $this->provisionRule(
                $owner,
                $tenant,
                name: 'Cobranzas: aviso previo al vencimiento',
                dueDateKey: $dueDateKey,
                offsetDirection: 'before',
                offsetValue: (int) $this->option('reminder-days'),
                template: $reminderTemplate,
                dueDateFieldForParams: $dueDateKey,
                timezone: $timezone,
                condition: [
                    'field' => 'contact.custom_data.'.$statusKey,
                    'operator' => 'in',
                    'value' => ['impago', 'en_prueba'],
                ],
            );

            $this->provisionRule(
                $owner,
                $tenant,
                name: 'Cobranzas: reclamo posterior al vencimiento',
                dueDateKey: $dueDateKey,
                offsetDirection: 'after',
                offsetValue: $overdueDays,
                template: $overdueTemplate,
                dueDateFieldForParams: $dueDateKey,
                timezone: $timezone,
                condition: [
                    'field' => 'contact.custom_data.'.$statusKey,
                    'operator' => 'equals',
                    'value' => 'impago',
                ],
            );

            if ($trialTemplate) {
                $this->provisionRule(
                    $owner,
                    $tenant,
                    name: 'Cobranzas: aviso de fin de prueba',
                    dueDateKey: $dueDateKey,
                    offsetDirection: 'before',
                    offsetValue: (int) $this->option('reminder-days'),
                    template: $trialTemplate,
                    dueDateFieldForParams: $dueDateKey,
                    timezone: $timezone,
                    condition: [
                        'field' => 'contact.custom_data.'.$statusKey,
                        'operator' => 'equals',
                        'value' => 'en_prueba',
                    ],
                );
            }
        } catch (ValidationException $exception) {
            $this->error('No se pudo crear una regla: '.collect($exception->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }

        $this->info("Tenant #{$tenant->id} ({$tenant->name}) provisionado para cobranzas.");
        $this->line("Campos: {$dueDateField->key}, {$statusField->key}, {$overdueCyclesField->key}");
        $this->line("grace_days={$graceDays} (> overdue-days={$overdueDays})");

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

    private function resolveOwner(Tenant $tenant): ?User
    {
        if (! $tenant->owner_role_id) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $tenant->owner_role_id))
            ->first();
    }

    /**
     * @param  array{field: string, operator: string, value: mixed}  $condition
     */
    private function provisionRule(
        User $owner,
        Tenant $tenant,
        string $name,
        string $dueDateKey,
        string $offsetDirection,
        int $offsetValue,
        WhatsAppTemplate $template,
        string $dueDateFieldForParams,
        string $timezone,
        array $condition,
    ): void {
        $existing = AutomationRule::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->first();

        if ($existing) {
            $this->line("Ya existe la regla «{$name}» — sin cambios.");

            return;
        }

        $channel = Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('whatsapp_config_id', $template->whatsapp_config_id)
            ->first();

        if (! $channel) {
            throw ValidationException::withMessages([
                'channel' => "No se encontró un canal de WhatsApp para la plantilla «{$template->name}».",
            ]);
        }

        $expectedParams = $template->expectedBodyParameters();
        $parameters = array_map(fn ($paramName) => [
            'component' => 'body',
            'name' => $paramName,
            'source' => 'field',
            'path' => 'contact.custom_data.'.$dueDateFieldForParams,
        ], $expectedParams);

        $rule = $this->rules->create([
            'name' => $name,
            'trigger_type' => 'date.reached',
            'trigger_config' => [
                'subject' => 'contact',
                'field' => 'contact.custom_data.'.$dueDateKey,
                'offset_direction' => $offsetDirection,
                'offset_value' => $offsetValue,
                'offset_unit' => 'days',
                'local_time' => '09:00',
                // Recurrencia desactivada a propósito: DateAutomationScheduler
                // precalcula todas las ocurrencias por adelantado, así que un
                // cliente que paga o se da de baja igual recibiría los envíos
                // ya agendados. El ciclo lo avanza billing:roll-cycle (Fase 5).
                'recurrence' => ['enabled' => false],
            ],
            'conditions' => $condition,
            'timezone' => $timezone,
            'actions' => [[
                'type' => 'whatsapp_template',
                'config' => [
                    'channel_id' => $channel->id,
                    'template_id' => $template->id,
                    'parameters' => $parameters,
                ],
            ]],
        ], $owner);

        $this->rules->activate($rule);
        $this->line("Regla creada y activada: «{$name}» (#{$rule->id}).");
    }
}
