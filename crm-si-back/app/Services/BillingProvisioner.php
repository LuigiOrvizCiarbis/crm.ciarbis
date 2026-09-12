<?php

namespace App\Services;

use App\Automation\AutomationRuleService;
use App\Enums\ContactFieldType;
use App\Models\AutomationRule;
use App\Models\BillingConfig;
use App\Models\Channel;
use App\Models\ContactField;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Deja un tenant listo para cobranzas: campos custom, BillingConfig y las
 * AutomationRule de recordatorio/reclamo.
 *
 * Vive en un service y no sólo en el comando porque hay dos disparadores: el
 * operador que corre `billing:provision` a mano, y el webhook de Meta que
 * completa el provisioning cuando aprueba las plantillas pedidas desde la UI
 * (BillingTemplateDrafts). Duplicar esta lógica en los dos lados garantizaría
 * que se desincronicen.
 */
class BillingProvisioner
{
    /**
     * Nombre de la regla que corresponde a cada plantilla, indexado por la
     * key del borrador (BillingTemplateDrafts::REMINDER, etc.).
     *
     * Es la fuente de verdad de qué reglas componen el módulo: el job que
     * completa el provisioning las usa para saber cuáles faltan todavía, en
     * vez de asumir que una BillingConfig habilitada significa "terminado".
     *
     * @var array<string, string>
     */
    public const RULE_NAMES = [
        'reminder' => 'Cobranzas: aviso previo al vencimiento',
        'overdue' => 'Cobranzas: reclamo posterior al vencimiento',
        'trial' => 'Cobranzas: aviso de fin de prueba',
    ];

    public function __construct(
        private readonly AutomationRuleService $rules,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * Keys de las reglas que este tenant todavía no tiene creadas.
     *
     * @param  list<string>  $keys  Keys a verificar (las que tienen plantilla aprobada).
     * @return list<string>
     */
    public function missingRuleKeys(Tenant $tenant, array $keys): array
    {
        $expected = array_intersect_key(self::RULE_NAMES, array_flip($keys));

        if ($expected === []) {
            return [];
        }

        $existing = AutomationRule::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('name', array_values($expected))
            ->pluck('name')
            ->all();

        return array_values(array_keys(array_diff($expected, $existing)));
    }

    /**
     * @param  array{reminder?: WhatsAppTemplate, overdue?: WhatsAppTemplate, trial?: WhatsAppTemplate}  $templates
     * @param  array<string, mixed>  $options
     * @return array{config: BillingConfig, rules: list<string>}
     */
    public function provision(Tenant $tenant, array $templates, array $options = []): array
    {
        // Los roles de Spatie están scopeados por team: sin esto, resolveOwner()
        // filtra contra el team que haya quedado activo del último request/job
        // que corrió en este proceso, no contra este tenant (mismo patrón que
        // ResyncSystemRoles y MigrateUsersToSpatieRoles).
        $this->registrar->setPermissionsTeamId($tenant->id);
        $this->registrar->forgetCachedPermissions();

        $dueDateKey = $options['due_date_field'] ?? 'vencimiento';
        $statusKey = $options['status_field'] ?? 'estado';
        $overdueCyclesKey = $options['overdue_cycles_field'] ?? 'ciclos_impagos';
        $timezone = $options['timezone'] ?? 'America/Argentina/Buenos_Aires';
        $graceDays = (int) ($options['grace_days'] ?? 5);
        $reminderDays = (int) ($options['reminder_days'] ?? 3);
        $overdueDays = (int) ($options['overdue_days'] ?? 2);
        // Las reglas nacen en Draft cuando el provisioning lo dispara el
        // webhook: activar solo, sobre vencimientos ya cargados, puede
        // disparar una ráfaga de mensajes que nadie revisó.
        $activateRules = (bool) ($options['activate_rules'] ?? false);

        if ($graceDays <= $overdueDays) {
            throw ValidationException::withMessages([
                'grace_days' => "grace_days ({$graceDays}) debe ser mayor que overdue_days ({$overdueDays}): si no, billing:roll-cycle cancela el reclamo antes de que salga.",
            ]);
        }

        $owner = $this->resolveOwner($tenant);
        if (! $owner) {
            throw ValidationException::withMessages([
                'owner' => "El tenant #{$tenant->id} no tiene un usuario Owner resoluble.",
            ]);
        }

        $fields = $this->provisionFields($tenant, $dueDateKey, $statusKey, $overdueCyclesKey);
        $config = $this->provisionConfig($tenant, $fields, $timezone, $graceDays);

        $created = [];

        if (isset($templates['reminder'])) {
            $created[] = $this->provisionRule($owner, $tenant, [
                'name' => self::RULE_NAMES['reminder'],
                'due_date_key' => $dueDateKey,
                'offset_direction' => 'before',
                'offset_value' => $reminderDays,
                'template' => $templates['reminder'],
                'timezone' => $timezone,
                'condition' => [
                    'field' => 'contact.custom_data.'.$statusKey,
                    'operator' => 'in',
                    'value' => ['impago', 'en_prueba'],
                ],
                'activate' => $activateRules,
            ]);
        }

        if (isset($templates['overdue'])) {
            $created[] = $this->provisionRule($owner, $tenant, [
                'name' => self::RULE_NAMES['overdue'],
                'due_date_key' => $dueDateKey,
                'offset_direction' => 'after',
                'offset_value' => $overdueDays,
                'template' => $templates['overdue'],
                'timezone' => $timezone,
                'condition' => [
                    'field' => 'contact.custom_data.'.$statusKey,
                    'operator' => 'equals',
                    'value' => 'impago',
                ],
                'activate' => $activateRules,
            ]);
        }

        if (isset($templates['trial'])) {
            $created[] = $this->provisionRule($owner, $tenant, [
                'name' => self::RULE_NAMES['trial'],
                'due_date_key' => $dueDateKey,
                'offset_direction' => 'before',
                'offset_value' => $reminderDays,
                'template' => $templates['trial'],
                'timezone' => $timezone,
                'condition' => [
                    'field' => 'contact.custom_data.'.$statusKey,
                    'operator' => 'equals',
                    'value' => 'en_prueba',
                ],
                'activate' => $activateRules,
            ]);
        }

        return ['config' => $config, 'rules' => array_values(array_filter($created))];
    }

    /**
     * @return array{due: ContactField, status: ContactField, overdue: ContactField}
     */
    private function provisionFields(Tenant $tenant, string $dueDateKey, string $statusKey, string $overdueCyclesKey): array
    {
        $due = ContactField::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'key' => $dueDateKey],
            ['label' => 'Vencimiento', 'type' => ContactFieldType::Date, 'display_order' => 900],
        );

        $status = ContactField::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'key' => $statusKey],
            [
                'label' => 'Estado de pago',
                'type' => ContactFieldType::Select,
                'options' => ['choices' => BillingConfig::STATUSES],
                'display_order' => 901,
            ],
        );

        // El campo puede haber sido creado antes sin alguna de las choices que
        // el motor necesita: se completan en vez de fallar, así una corrida
        // sobre un tenant con campos armados a mano converge igual.
        $existingChoices = is_array($status->options['choices'] ?? null) ? $status->options['choices'] : [];
        $missingChoices = array_diff(BillingConfig::STATUSES, $existingChoices);
        if ($missingChoices !== []) {
            $status->update(['options' => ['choices' => array_values([...$existingChoices, ...$missingChoices])]]);
        }

        $overdue = ContactField::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'key' => $overdueCyclesKey],
            ['label' => 'Ciclos impagos', 'type' => ContactFieldType::Number, 'display_order' => 902],
        );

        ContactField::clearTenantCache($tenant->id);

        return ['due' => $due, 'status' => $status, 'overdue' => $overdue];
    }

    /** @param array{due: ContactField, status: ContactField, overdue: ContactField} $fields */
    private function provisionConfig(Tenant $tenant, array $fields, string $timezone, int $graceDays): BillingConfig
    {
        $config = BillingConfig::withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenant->id]);
        $config->tenant_id = $tenant->id;
        $config->due_date_field_key = $fields['due']->key;
        $config->status_field_key = $fields['status']->key;
        $config->overdue_cycles_field_key = $fields['overdue']->key;
        $config->cycle_unit = 'months';
        $config->cycle_length = 1;
        $config->timezone = $timezone;
        $config->grace_days = $graceDays;
        $config->enabled = true;
        $config->save();

        return $config;
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
     * @param  array{name: string, due_date_key: string, offset_direction: string, offset_value: int, template: WhatsAppTemplate, timezone: string, condition: array<string, mixed>, activate: bool}  $spec
     * @return string|null Nombre de la regla creada, o null si ya existía.
     */
    private function provisionRule(User $owner, Tenant $tenant, array $spec): ?string
    {
        $existing = AutomationRule::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', $spec['name'])
            ->exists();

        if ($existing) {
            return null;
        }

        $template = $spec['template'];

        $channel = Channel::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('whatsapp_config_id', $template->whatsapp_config_id)
            ->first();

        if (! $channel) {
            throw ValidationException::withMessages([
                'channel' => "No se encontró un canal de WhatsApp para la plantilla «{$template->name}».",
            ]);
        }

        // Los parámetros salen de la plantilla real: su cantidad y nombres los
        // define el texto que aprobó Meta, no se pueden hardcodear.
        $parameters = array_map(fn ($paramName) => [
            'component' => 'body',
            'name' => $paramName,
            'source' => 'field',
            'path' => $paramName === 'nombre'
                ? 'contact.name'
                : 'contact.custom_data.'.$spec['due_date_key'],
        ], $template->expectedBodyParameters());

        $rule = $this->rules->create([
            'name' => $spec['name'],
            'trigger_type' => 'date.reached',
            'trigger_config' => [
                'subject' => 'contact',
                'field' => 'contact.custom_data.'.$spec['due_date_key'],
                'offset_direction' => $spec['offset_direction'],
                'offset_value' => $spec['offset_value'],
                'offset_unit' => 'days',
                'local_time' => '09:00',
                // Recurrencia desactivada a propósito: DateAutomationScheduler
                // precalcula todas las ocurrencias por adelantado, así que un
                // cliente que paga o se da de baja igual recibiría los envíos
                // ya agendados. El ciclo lo avanza billing:roll-cycle.
                'recurrence' => ['enabled' => false],
            ],
            'conditions' => $spec['condition'],
            'timezone' => $spec['timezone'],
            'actions' => [[
                'type' => 'whatsapp_template',
                'config' => [
                    'channel_id' => $channel->id,
                    'template_id' => $template->id,
                    'parameters' => $parameters,
                ],
            ]],
        ], $owner);

        if ($spec['activate']) {
            $this->rules->activate($rule);
        }

        return $spec['name'];
    }
}
