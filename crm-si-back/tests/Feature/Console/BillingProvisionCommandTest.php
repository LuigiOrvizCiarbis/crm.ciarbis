<?php

namespace Tests\Feature\Console;

use App\Enums\AutomationRuleStatus;
use App\Enums\ChannelType;
use App\Enums\ContactFieldType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Models\AutomationRule;
use App\Models\BillingConfig;
use App\Models\Channel;
use App\Models\ContactField;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BillingProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisions_fields_config_and_rules(): void
    {
        [$tenant, , $reminder, $overdue] = $this->createSetup();

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--reminder-template' => $reminder->id,
            '--overdue-template' => $overdue->id,
        ])->assertSuccessful();

        $this->assertDatabaseHas('contact_fields', ['tenant_id' => $tenant->id, 'key' => 'vencimiento', 'type' => ContactFieldType::Date->value]);
        $this->assertDatabaseHas('contact_fields', ['tenant_id' => $tenant->id, 'key' => 'estado', 'type' => ContactFieldType::Select->value]);
        $this->assertDatabaseHas('contact_fields', ['tenant_id' => $tenant->id, 'key' => 'ciclos_impagos', 'type' => ContactFieldType::Number->value]);

        $config = BillingConfig::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertTrue($config->enabled);
        $this->assertSame('vencimiento', $config->due_date_field_key);
        $this->assertSame(5, $config->grace_days);

        $rules = AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->get();
        $this->assertCount(2, $rules);
        $this->assertTrue($rules->every(fn (AutomationRule $r) => $r->status === AutomationRuleStatus::Active));
    }

    public function test_status_field_choices_include_the_three_literal_states(): void
    {
        [$tenant, , $reminder, $overdue] = $this->createSetup();

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--reminder-template' => $reminder->id,
            '--overdue-template' => $overdue->id,
        ])->assertSuccessful();

        $statusField = ContactField::where('tenant_id', $tenant->id)->where('key', 'estado')->firstOrFail();
        $this->assertSame(['al_dia', 'impago', 'en_prueba'], $statusField->options['choices']);
    }

    public function test_provisions_trial_rule_only_when_trial_template_is_given(): void
    {
        [$tenant, $channel, $reminder, $overdue] = $this->createSetup();

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--reminder-template' => $reminder->id,
            '--overdue-template' => $overdue->id,
        ])->assertSuccessful();

        $this->assertSame(2, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $trial = $this->createTemplate($channel, 'trial_ends', TemplateCategory::Utility, TemplateStatus::Approved, '{{1}}');

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--reminder-template' => $reminder->id,
            '--overdue-template' => $overdue->id,
            '--trial-template' => $trial->id,
        ])->assertSuccessful();

        $this->assertSame(3, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('automation_rules', ['tenant_id' => $tenant->id, 'name' => 'Cobranzas: aviso de fin de prueba']);
    }

    public function test_running_twice_does_not_duplicate_anything(): void
    {
        [$tenant, , $reminder, $overdue] = $this->createSetup();

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--reminder-template' => $reminder->id,
            '--overdue-template' => $overdue->id,
        ])->assertSuccessful();

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--reminder-template' => $reminder->id,
            '--overdue-template' => $overdue->id,
        ])->assertSuccessful();

        $this->assertSame(1, ContactField::where('tenant_id', $tenant->id)->where('key', 'vencimiento')->count());
        $this->assertSame(1, BillingConfig::where('tenant_id', $tenant->id)->count());
        $this->assertSame(2, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_fails_cleanly_when_reminder_template_is_missing(): void
    {
        [$tenant, , , $overdue] = $this->createSetup();

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--overdue-template' => $overdue->id,
        ])->assertFailed();

        $this->assertDatabaseCount('billing_configs', 0);
        $this->assertDatabaseCount('automation_rules', 0);
    }

    public function test_fails_cleanly_when_template_is_not_approved(): void
    {
        [$tenant, $channel, , $overdue] = $this->createSetup();
        $pending = $this->createTemplate($channel, 'pending_reminder', TemplateCategory::Utility, TemplateStatus::Pending, '{{1}}');

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--reminder-template' => $pending->id,
            '--overdue-template' => $overdue->id,
        ])->assertFailed();

        $this->assertDatabaseCount('billing_configs', 0);
    }

    public function test_fails_when_grace_days_is_not_greater_than_overdue_days(): void
    {
        [$tenant, , $reminder, $overdue] = $this->createSetup();

        $this->artisan('billing:provision', [
            'tenant' => $tenant->id,
            '--reminder-template' => $reminder->id,
            '--overdue-template' => $overdue->id,
            '--overdue-days' => 5,
            '--grace-days' => 3,
        ])->assertFailed();

        $this->assertDatabaseCount('billing_configs', 0);
    }

    public function test_tenant_isolation(): void
    {
        [$tenantA, , $reminderA, $overdueA] = $this->createSetup();
        [$tenantB, , $reminderB, $overdueB] = $this->createSetup();

        $this->artisan('billing:provision', [
            'tenant' => $tenantA->id,
            '--reminder-template' => $reminderA->id,
            '--overdue-template' => $overdueA->id,
        ])->assertSuccessful();

        $this->assertDatabaseCount('billing_configs', 1);
        $this->assertSame(0, ContactField::where('tenant_id', $tenantB->id)->count());
        $this->assertSame(0, AutomationRule::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());
    }

    /**
     * @return array{0: Tenant, 1: Channel, 2: WhatsAppTemplate, 3: WhatsAppTemplate}
     */
    private function createSetup(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Tenant '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $owner->assignRole('Owner');
        $tenant->refresh(); // owner_role_id se resuelve en provisionDefaultRoles

        $whatsappConfig = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0101',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp principal',
            'status' => 'active',
            'whatsapp_config_id' => $whatsappConfig->id,
        ]);

        $reminder = $this->createTemplate($channel, 'billing_reminder', TemplateCategory::Utility, TemplateStatus::Approved, '{{nombre}}');
        $overdue = $this->createTemplate($channel, 'billing_overdue', TemplateCategory::Utility, TemplateStatus::Approved, '{{nombre}}');

        return [$tenant, $channel, $reminder, $overdue];
    }

    private function createTemplate(Channel $channel, string $name, TemplateCategory $category, TemplateStatus $status, string $bodyText): WhatsAppTemplate
    {
        return WhatsAppTemplate::create([
            'tenant_id' => $channel->tenant_id,
            'whatsapp_config_id' => $channel->whatsapp_config_id,
            'external_id' => 'template-'.uniqid(),
            'name' => $name,
            'language' => 'es_AR',
            'category' => $category,
            'status' => $status,
            'components' => [['type' => 'BODY', 'text' => "Hola {$bodyText}"]],
            'synced_at' => now(),
        ]);
    }
}
