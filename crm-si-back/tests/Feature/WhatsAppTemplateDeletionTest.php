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
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WhatsAppTemplateDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_deletes_the_selected_template_from_meta_and_locally(): void
    {
        [$owner, $channel] = $this->context();
        $template = $this->template($channel, 'meta-template-1', 'order_ready');
        Http::fake(['https://graph.facebook.com/*' => Http::response(['success' => true])]);
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/channels/{$channel->id}/templates/{$template->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('whatsapp_templates', ['id' => $template->id]);
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'DELETE'
                && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/waba-test/message_templates')
                && ($query['hsm_id'] ?? null) === 'meta-template-1'
                && ($query['name'] ?? null) === 'order_ready';
        });
    }

    public function test_user_without_delete_permission_is_rejected(): void
    {
        [$owner, $channel] = $this->context();
        $member = User::factory()->create(['tenant_id' => $owner->tenant_id, 'role' => UserRole::EMPLOYEE]);
        $member->assignRole('Member');
        $channel->update(['user_id' => $member->id]);
        $template = $this->template($channel);
        Http::fake();
        Sanctum::actingAs($member);

        $this->deleteJson("/api/channels/{$channel->id}/templates/{$template->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('whatsapp_templates', ['id' => $template->id]);
        Http::assertNothingSent();
    }

    public function test_template_from_another_channel_is_not_deleted(): void
    {
        [$owner, $channel] = $this->context();
        $otherConfig = $this->config('other-waba');
        $otherChannel = Channel::create([
            'tenant_id' => $owner->tenant_id,
            'user_id' => $owner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Other WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $otherConfig->id,
        ]);
        $template = $this->template($otherChannel);
        Http::fake();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/channels/{$channel->id}/templates/{$template->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('whatsapp_templates', ['id' => $template->id]);
        Http::assertNothingSent();
    }

    public function test_channel_from_another_tenant_is_not_accessible(): void
    {
        [$owner] = $this->context();
        [, $foreignChannel] = $this->context();
        $foreignTemplate = $this->template($foreignChannel);
        Http::fake();
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/channels/{$foreignChannel->id}/templates/{$foreignTemplate->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('whatsapp_templates', ['id' => $foreignTemplate->id]);
        Http::assertNothingSent();
    }

    public function test_local_template_is_preserved_when_meta_rejects_deletion(): void
    {
        [$owner, $channel] = $this->context();
        $template = $this->template($channel);
        Http::fake(['https://graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'The access token has expired.'],
        ], 401)]);
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/channels/{$channel->id}/templates/{$template->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Meta no pudo eliminar la plantilla: The access token has expired.');

        $this->assertDatabaseHas('whatsapp_templates', ['id' => $template->id]);
    }

    public function test_deletion_is_idempotent_when_meta_reports_template_does_not_exist(): void
    {
        [$owner, $channel] = $this->context();
        $template = $this->template($channel);
        // El servicio reconoce este caso por `error_subcode` (2388023), no por
        // el texto del detalle: matchear por mensaje no es confiable y borraría
        // el espejo local ante cualquier "Invalid parameter" de Meta.
        Http::fake(['https://graph.facebook.com/*' => Http::response([
            'error' => [
                'message' => 'Invalid parameter',
                'error_subcode' => 2388023,
                'error_data' => ['details' => 'Template name does not exist in the translation.'],
            ],
        ], 400)]);
        Sanctum::actingAs($owner);

        $this->deleteJson("/api/channels/{$channel->id}/templates/{$template->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('whatsapp_templates', ['id' => $template->id]);
    }

    /** @return array{0: User, 1: Channel} */
    private function context(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $owner->assignRole('Owner');
        $config = $this->config('waba-test');

        return [$owner, Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ])];
    }

    private function config(string $wabaId): WhatsAppConfig
    {
        return WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.$wabaId,
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => $wabaId,
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);
    }

    private function template(Channel $channel, string $externalId = 'meta-template', string $name = 'hello'): WhatsAppTemplate
    {
        return WhatsAppTemplate::create([
            'tenant_id' => $channel->tenant_id,
            'whatsapp_config_id' => $channel->whatsapp_config_id,
            'external_id' => $externalId,
            'name' => $name,
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola']],
        ]);
    }
}
