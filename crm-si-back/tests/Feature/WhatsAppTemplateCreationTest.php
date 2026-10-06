<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppTemplateService;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WhatsAppTemplateCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_named_template_and_it_is_persisted_as_pending(): void
    {
        [$user, $channel] = $this->context();
        Http::fake([
            'https://graph.facebook.com/*/waba-test/message_templates' => Http::response([
                'id' => 'meta-template-1', 'status' => 'PENDING', 'category' => 'UTILITY',
            ], 200),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/channels/{$channel->id}/templates", [
            'name' => 'order_ready',
            'language' => 'es_AR',
            'category' => 'UTILITY',
            'parameter_format' => 'named',
            'components' => [[
                'type' => 'BODY',
                'text' => 'Hola {{nombre}}, tu pedido está listo.',
                'example' => ['body_text_named_params' => [['param_name' => 'nombre', 'example' => 'Ana']]],
            ]],
        ]);

        $response->assertCreated()->assertJsonPath('status', 'PENDING');
        $this->assertDatabaseHas('whatsapp_templates', [
            'whatsapp_config_id' => $channel->whatsapp_config_id,
            'external_id' => 'meta-template-1',
            'status' => 'PENDING',
        ]);
    }

    public function test_template_creation_requires_examples_for_named_variables(): void
    {
        [$user, $channel] = $this->context();
        Sanctum::actingAs($user);

        $this->postJson("/api/channels/{$channel->id}/templates", [
            'name' => 'order_ready', 'language' => 'es_AR', 'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hola {{nombre}}']],
        ])->assertStatus(422);
    }

    public function test_meta_creation_error_includes_the_specific_user_message(): void
    {
        [$user, $channel] = $this->context();
        Http::fake([
            'https://graph.facebook.com/*/waba-test/message_templates' => Http::response([
                'error' => [
                    'message' => 'Invalid parameter',
                    'error_user_msg' => 'New Spanish (ARG) content cannot be added while the existing content is being deleted.',
                ],
            ], 400),
        ]);

        try {
            app(WhatsAppTemplateService::class)->createTemplate($channel->whatsappConfig, $user->tenant_id, [
                'name' => 'invoice_test',
                'language' => 'es_AR',
                'category' => 'UTILITY',
                'components' => [['type' => 'BODY', 'text' => 'Hola']],
            ]);
            $this->fail('La creación debía fallar.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('New Spanish (ARG) content cannot be added', $exception->getMessage());
            $this->assertStringNotContainsString('Invalid parameter', $exception->getMessage());
        }
    }

    public function test_member_can_list_templates_for_a_channel_they_own_without_channel_permission(): void
    {
        [$owner, $channel] = $this->context();
        $member = User::factory()->create(['tenant_id' => $owner->tenant_id, 'role' => UserRole::EMPLOYEE]);
        $member->assignRole('Member');
        $channel->update(['user_id' => $member->id]);
        WhatsAppTemplate::create([
            'tenant_id' => $owner->tenant_id,
            'whatsapp_config_id' => $channel->whatsapp_config_id,
            'external_id' => 'meta-member-template',
            'name' => 'member_visible',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola']],
        ]);

        Sanctum::actingAs($member);

        $this->getJson("/api/channels/{$channel->id}/templates")
            ->assertOk()
            ->assertJsonPath('0.name', 'member_visible');
    }

    /** @return array{0: User, 1: Channel} */
    private function context(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) Permission::findOrCreate($permission, 'web');
        $registrar->forgetCachedPermissions();
        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');
        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789', 'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test', 'bussines_token' => Crypt::encryptString('test-token'),
        ]);
        return [$user, Channel::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp', 'status' => 'active', 'whatsapp_config_id' => $config->id,
        ])];
    }
}
