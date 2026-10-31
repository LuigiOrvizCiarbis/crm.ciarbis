<?php

namespace Tests\Feature;

use App\Automation\DateAutomationScheduler;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\ChannelType;
use App\Enums\ContactFieldType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Models\AutomationRule;
use App\Models\BillingConfig;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BillingRollCycleTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Argentina/Buenos_Aires';

    public function test_al_dia_advances_date_switches_to_impago_and_resets_counter(): void
    {
        [$owner] = $this->context();
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $contact = $this->createContact($owner->tenant_id, 'al_dia', now(self::TZ)->subDays(10)->format('Y-m-d'), 0);

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $contact->refresh();
        $this->assertSame('impago', $contact->custom_data['estado']);
        $this->assertSame(0, $contact->custom_data['ciclos_impagos']);
        $this->assertTrue(
            CarbonImmutable::parse($contact->custom_data['vencimiento'])->isFuture(),
            'La fecha nueva debe quedar en el futuro.',
        );
    }

    public function test_impago_advances_date_and_increments_counter(): void
    {
        [$owner] = $this->context();
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $contact = $this->createContact($owner->tenant_id, 'impago', now(self::TZ)->subDays(10)->format('Y-m-d'), 2);

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $contact->refresh();
        $this->assertSame('impago', $contact->custom_data['estado']);
        $this->assertSame(3, $contact->custom_data['ciclos_impagos']);
    }

    public function test_en_prueba_advances_date_and_switches_to_impago_with_counter_one(): void
    {
        [$owner] = $this->context();
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $contact = $this->createContact($owner->tenant_id, 'en_prueba', now(self::TZ)->subDays(10)->format('Y-m-d'), 0);

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $contact->refresh();
        $this->assertSame('impago', $contact->custom_data['estado']);
        $this->assertSame(1, $contact->custom_data['ciclos_impagos']);
    }

    public function test_overdue_contact_gets_rescheduled_runs_after_roll(): void
    {
        [$owner, $channel, $template] = $this->context();
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $contact = $this->createContact($owner->tenant_id, 'impago', now(self::TZ)->subDays(10)->format('Y-m-d'), 1);

        $rule = $this->activeRule($owner, $channel, $template, offsetDirection: 'after', offsetValue: 2);

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $this->assertGreaterThan(
            0,
            $rule->runs()->where('status', AutomationRunStatus::Scheduled)->where('scheduled_for', '>', now())->count(),
            'El moroso debe tener una run futura agendada tras el roll — la regresión que mata el diseño anterior.',
        );
    }

    public function test_grace_period_protects_the_after_claim_from_being_cancelled(): void
    {
        [$owner, $channel, $template] = $this->context();
        // grace_days=3, overdue-days=2: la invariante de la Fase 4.
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $dueYesterday = now(self::TZ)->subDay()->format('Y-m-d');
        $contact = $this->createContact($owner->tenant_id, 'impago', $dueYesterday, 0);

        $rule = $this->activeRule($owner, $channel, $template, offsetDirection: 'after', offsetValue: 2);
        app(DateAutomationScheduler::class)->scheduleSubject($rule, $contact);
        $pendingRunId = $rule->runs()->where('status', AutomationRunStatus::Scheduled)->value('id');
        $this->assertNotNull($pendingRunId, 'Precondición: la run del reclamo debe existir antes del roll.');

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $contact->refresh();
        $this->assertSame($dueYesterday, $contact->custom_data['vencimiento'], 'Dentro de la gracia, la fecha no debe moverse.');
        $this->assertSame(
            AutomationRunStatus::Scheduled,
            $rule->runs()->find($pendingRunId)->status,
            'La run del reclamo no debe cancelarse mientras dure la gracia.',
        );
    }

    public function test_timezone_alignment_between_billing_config_and_rule(): void
    {
        [$owner, $channel, $template] = $this->context();
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $this->createContact($owner->tenant_id, 'impago', now(self::TZ)->subDays(10)->format('Y-m-d'), 0);

        $this->activeRule($owner, $channel, $template, offsetDirection: 'after', offsetValue: 2, timezone: 'UTC');

        $this->artisan('billing:roll-cycle')->assertFailed();
    }

    public function test_idempotent_two_runs_same_day_advance_only_once(): void
    {
        [$owner] = $this->context();
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $contact = $this->createContact($owner->tenant_id, 'impago', now(self::TZ)->subDays(10)->format('Y-m-d'), 0);

        $this->artisan('billing:roll-cycle')->assertSuccessful();
        $dueAfterFirstRoll = $contact->refresh()->custom_data['vencimiento'];

        $this->artisan('billing:roll-cycle')->assertSuccessful();
        $contact->refresh();

        $this->assertSame($dueAfterFirstRoll, $contact->custom_data['vencimiento']);
        $this->assertSame(1, $contact->custom_data['ciclos_impagos']);
    }

    public function test_externally_managed_contact_is_untouched(): void
    {
        [$owner] = $this->context();
        ContactField::create([
            'tenant_id' => $owner->tenant_id,
            'key' => 'gestionado_externo',
            'label' => 'Gestionado externo',
            'type' => ContactFieldType::Boolean,
            'display_order' => 3,
        ]);
        $config = $this->createConfig($owner->tenant_id, graceDays: 3, externallyManagedKey: 'gestionado_externo');

        $due = now(self::TZ)->subDays(10)->format('Y-m-d');
        $managed = Contact::create([
            'tenant_id' => $owner->tenant_id,
            'name' => 'Externo',
            'source' => 'webhook',
            'custom_data' => ['vencimiento' => $due, 'estado' => 'impago', 'ciclos_impagos' => 0, 'gestionado_externo' => true],
        ]);

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $managed->refresh();
        $this->assertSame($due, $managed->custom_data['vencimiento']);
        $this->assertSame(0, $managed->custom_data['ciclos_impagos']);
    }

    public function test_within_grace_period_contact_is_not_touched(): void
    {
        [$owner] = $this->context();
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $due = now(self::TZ)->subDay()->format('Y-m-d'); // venció ayer, gracia de 3 días
        $contact = $this->createContact($owner->tenant_id, 'impago', $due, 0);

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $contact->refresh();
        $this->assertSame($due, $contact->custom_data['vencimiento']);
    }

    public function test_month_end_boundary_does_not_overflow(): void
    {
        [$owner] = $this->context();
        $config = $this->createConfig($owner->tenant_id, graceDays: 3);
        $contact = $this->createContact($owner->tenant_id, 'al_dia', '2026-01-31', 0);

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $this->assertSame('2026-02-28', $contact->refresh()->custom_data['vencimiento']);
    }

    public function test_missing_contact_field_skips_tenant_without_breaking_others(): void
    {
        [$ownerA] = $this->context();
        [$ownerB] = $this->context();

        $configA = $this->createConfig($ownerA->tenant_id, graceDays: 3);
        // Borra (soft delete) el campo de vencimiento de A después de crear la config.
        ContactField::where('tenant_id', $ownerA->tenant_id)->where('key', 'vencimiento')->delete();

        $configB = $this->createConfig($ownerB->tenant_id, graceDays: 3);
        $contactB = $this->createContact($ownerB->tenant_id, 'impago', now(self::TZ)->subDays(10)->format('Y-m-d'), 0);

        // El guard defensivo de A no lanza excepción — se saltea con warning
        // (así lo pide el plan), así que el comando en conjunto sigue exitoso.
        // Lo que importa es que B convergió igual, sin que A lo bloquee.
        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $this->assertSame(1, $contactB->refresh()->custom_data['ciclos_impagos'] ?? null);
    }

    public function test_cross_tenant_isolation(): void
    {
        [$ownerA] = $this->context();
        [$ownerB] = $this->context();

        $this->createConfig($ownerA->tenant_id, graceDays: 3);
        $this->createConfig($ownerB->tenant_id, graceDays: 3);

        $contactA = $this->createContact($ownerA->tenant_id, 'impago', now(self::TZ)->subDays(10)->format('Y-m-d'), 0);
        $contactB = $this->createContact($ownerB->tenant_id, 'al_dia', now(self::TZ)->subDays(10)->format('Y-m-d'), 0);

        $this->artisan('billing:roll-cycle')->assertSuccessful();

        $this->assertSame('impago', $contactA->refresh()->custom_data['estado']);
        $this->assertSame(1, $contactA->custom_data['ciclos_impagos']);
        $this->assertSame('impago', $contactB->refresh()->custom_data['estado']); // pasó de al_dia a impago
        $this->assertSame(0, $contactB->custom_data['ciclos_impagos']);
    }

    // --- Helpers -----------------------------------------------------

    private function createConfig(int $tenantId, int $graceDays, ?string $externallyManagedKey = null): BillingConfig
    {
        ContactField::firstOrCreate(
            ['tenant_id' => $tenantId, 'key' => 'vencimiento'],
            ['label' => 'Vencimiento', 'type' => ContactFieldType::Date, 'display_order' => 0],
        );
        ContactField::firstOrCreate(
            ['tenant_id' => $tenantId, 'key' => 'estado'],
            ['label' => 'Estado', 'type' => ContactFieldType::Select, 'options' => ['choices' => ['al_dia', 'impago', 'en_prueba']], 'display_order' => 1],
        );
        ContactField::firstOrCreate(
            ['tenant_id' => $tenantId, 'key' => 'ciclos_impagos'],
            ['label' => 'Ciclos impagos', 'type' => ContactFieldType::Number, 'display_order' => 2],
        );

        return BillingConfig::create([
            'tenant_id' => $tenantId,
            'enabled' => true,
            'due_date_field_key' => 'vencimiento',
            'status_field_key' => 'estado',
            'overdue_cycles_field_key' => 'ciclos_impagos',
            'externally_managed_field_key' => $externallyManagedKey,
            'cycle_unit' => 'months',
            'cycle_length' => 1,
            'timezone' => self::TZ,
            'grace_days' => $graceDays,
        ]);
    }

    private function createContact(int $tenantId, string $estado, string $vencimiento, int $ciclosImpagos): Contact
    {
        return Contact::create([
            'tenant_id' => $tenantId,
            'name' => 'Contacto '.uniqid(),
            'source' => 'manual',
            'custom_data' => ['vencimiento' => $vencimiento, 'estado' => $estado, 'ciclos_impagos' => $ciclosImpagos],
        ]);
    }

    private function activeRule(User $owner, Channel $channel, WhatsAppTemplate $template, string $offsetDirection, int $offsetValue, string $timezone = self::TZ): AutomationRule
    {
        $rule = AutomationRule::create([
            'tenant_id' => $owner->tenant_id,
            'created_by' => $owner->id,
            'name' => 'Cobranzas: reclamo',
            'status' => AutomationRuleStatus::Active,
            'trigger_type' => 'date.reached',
            'trigger_config' => [
                'subject' => 'contact',
                'field' => 'contact.custom_data.vencimiento',
                'offset_direction' => $offsetDirection,
                'offset_value' => $offsetValue,
                'offset_unit' => 'days',
                'local_time' => '09:00',
                'recurrence' => ['enabled' => false],
            ],
            'timezone' => $timezone,
            'activated_at' => now(),
        ]);
        $rule->actions()->create([
            'position' => 0,
            'type' => 'whatsapp_template',
            'config' => ['channel_id' => $channel->id, 'template_id' => $template->id, 'parameters' => [['name' => 'nombre', 'source' => 'field', 'path' => 'contact.name']]],
        ]);

        return $rule->load('actions');
    }

    /** @return array{0: User, 1: Channel, 2: WhatsAppTemplate} */
    private function context(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Tenant '.Str::random(8)]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $owner->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => Str::random(12),
            'display_phone_number' => '+541100000000',
            'waba_id' => Str::random(10),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
        $template = WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'external_id' => Str::uuid(),
            'name' => 'billing_claim',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola {{nombre}}']],
        ]);

        return [$owner, $channel, $template];
    }
}
