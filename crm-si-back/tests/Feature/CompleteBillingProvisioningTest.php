<?php

namespace Tests\Feature;

use App\Enums\AutomationRuleStatus;
use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Jobs\CompleteBillingProvisioningJob;
use App\Models\AutomationRule;
use App\Models\BillingConfig;
use App\Models\Channel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\BillingProvisioner;
use App\Support\BillingTemplateDrafts;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * El asistente de cobranzas pide las plantillas a Meta y espera: Meta las
 * devuelve en PENDING y avisa la aprobación por webhook
 * (message_template_status_update). Recién ahí se puede completar el
 * provisioning, porque las reglas exigen una plantilla aprobada.
 */
class CompleteBillingProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_the_last_template_provisions_the_module(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Approved);

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        $billing = BillingConfig::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($billing);
        $this->assertTrue($billing->enabled);

        $this->assertDatabaseHas('contact_fields', ['tenant_id' => $tenant->id, 'key' => 'vencimiento']);
        $this->assertSame(2, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_rules_are_left_in_draft_for_the_user_to_activate(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Approved);

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        // Activarlas solas, sobre vencimientos que el tenant ya tenía
        // cargados, dispararía una ráfaga de mensajes que nadie revisó.
        $rules = AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $this->assertTrue($rules->every(fn (AutomationRule $r) => $r->status === AutomationRuleStatus::Draft));
    }

    public function test_waits_while_a_required_template_is_still_pending(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Pending);

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        $this->assertDatabaseCount('billing_configs', 0);
        $this->assertSame(0, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_waits_when_a_required_template_was_rejected(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Rejected);

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        $this->assertDatabaseCount('billing_configs', 0);
    }

    public function test_trial_template_is_optional(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Approved);

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        // Sin la de trial se provisiona igual, con 2 reglas en vez de 3.
        $this->assertSame(2, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseMissing('automation_rules', [
            'tenant_id' => $tenant->id,
            'name' => 'Cobranzas: aviso de fin de prueba',
        ]);
    }

    public function test_is_idempotent_when_the_module_is_already_enabled(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Approved);

        // El webhook llega una vez por plantilla: el job corre varias veces.
        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));
        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        $this->assertSame(1, BillingConfig::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(2, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    /**
     * Meta aprueba cada plantilla por separado, así que la de trial puede
     * llegar después de las obligatorias. Usar el flag `enabled` de la config
     * como marcador de "ya provisioné" hacía que esta segunda pasada saliera
     * temprano y la regla de trial no se creara nunca.
     */
    public function test_trial_rule_is_created_when_its_template_is_approved_later(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Approved);
        $trial = $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::TRIAL]['name'], TemplateStatus::Pending);

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));
        $this->assertSame(2, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // Meta aprueba la de trial más tarde y vuelve a disparar el job.
        $trial->update(['status' => TemplateStatus::Approved]);
        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        $this->assertSame(3, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('automation_rules', [
            'tenant_id' => $tenant->id,
            'name' => BillingProvisioner::RULE_NAMES['trial'],
        ]);
    }

    /**
     * provisionConfig() corre antes de crear las reglas: si una regla falla,
     * la config ya quedó habilitada. El reintento del job tiene que poder
     * completar lo que falta en vez de darlo por terminado.
     */
    public function test_retries_create_the_rules_missing_after_a_partial_run(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Approved);

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        // Se simula la corrida parcial: la config quedó habilitada pero una de
        // las reglas no llegó a crearse.
        AutomationRule::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('name', BillingProvisioner::RULE_NAMES['overdue'])
            ->delete();
        $this->assertTrue(
            BillingConfig::withoutGlobalScopes()->where('tenant_id', $tenant->id)->value('enabled'),
        );

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        $this->assertDatabaseHas('automation_rules', [
            'tenant_id' => $tenant->id,
            'name' => BillingProvisioner::RULE_NAMES['overdue'],
        ]);
    }

    /**
     * Un tenant que ya tenía la config armada a mano (por ejemplo desde
     * tinker o el endpoint de configuración) quedaba excluido para siempre.
     */
    public function test_provisions_rules_for_a_tenant_with_a_preexisting_enabled_config(): void
    {
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');

        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::REMINDER]['name'], TemplateStatus::Approved);
        $this->makeTemplate($tenant, $config, $drafts[BillingTemplateDrafts::OVERDUE]['name'], TemplateStatus::Approved);

        BillingConfig::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'due_date_field_key' => 'vencimiento',
            'status_field_key' => 'estado',
            'overdue_cycles_field_key' => 'ciclos_impagos',
            'cycle_unit' => 'months',
            'cycle_length' => 1,
            'timezone' => 'America/Argentina/Buenos_Aires',
            'grace_days' => 5,
        ]);

        (new CompleteBillingProvisioningJob($tenant->id))->handle(app(BillingProvisioner::class));

        $this->assertSame(2, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_webhook_approval_queues_the_provisioning_job(): void
    {
        Queue::fake();
        [$tenant, $config] = $this->context();
        $drafts = collect(BillingTemplateDrafts::all($tenant))->keyBy('key');
        $template = $this->makeTemplate(
            $tenant,
            $config,
            $drafts[BillingTemplateDrafts::REMINDER]['name'],
            TemplateStatus::Pending,
            externalId: '900900900',
        );

        $this->postJson('/api/whatsapp-webhook', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $config->waba_id,
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'event' => 'APPROVED',
                        'message_template_id' => 900900900,
                        'message_template_name' => $template->name,
                        'reason' => 'NONE',
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertSame(TemplateStatus::Approved, $template->refresh()->status);
        Queue::assertPushed(
            CompleteBillingProvisioningJob::class,
            fn (CompleteBillingProvisioningJob $job): bool => $job->tenantId === $tenant->id,
        );
    }

    public function test_webhook_does_not_queue_provisioning_for_unrelated_templates(): void
    {
        Queue::fake();
        [$tenant, $config] = $this->context();
        $template = $this->makeTemplate($tenant, $config, 'promo_verano', TemplateStatus::Pending, externalId: '800800800');

        $this->postJson('/api/whatsapp-webhook', [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $config->waba_id,
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'event' => 'APPROVED',
                        'message_template_id' => 800800800,
                        'message_template_name' => $template->name,
                        'reason' => 'NONE',
                    ],
                ]],
            ]],
        ])->assertOk();

        Queue::assertNotPushed(CompleteBillingProvisioningJob::class);
    }

    private function makeTemplate(
        Tenant $tenant,
        WhatsAppConfig $config,
        string $name,
        TemplateStatus $status,
        ?string $externalId = null,
    ): WhatsAppTemplate {
        return WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'external_id' => $externalId ?? 'ext-'.uniqid(),
            'name' => $name,
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => $status,
            'components' => [['type' => 'BODY', 'text' => 'Hola {{nombre}}, vence el {{fecha}}']],
            'synced_at' => now(),
        ]);
    }

    /** @return array{0: Tenant, 1: WhatsAppConfig} */
    private function context(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Inmobiliaria '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);
        $tenant->refresh();

        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $owner->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0101',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);

        Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp principal',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);

        return [$tenant, $config];
    }
}
