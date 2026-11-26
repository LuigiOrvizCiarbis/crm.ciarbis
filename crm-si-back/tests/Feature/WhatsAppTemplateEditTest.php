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

class WhatsAppTemplateEditTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = [
        'type' => 'BODY',
        'text' => 'Tu pedido #{{pedido}} ya salió',
        'example' => ['body_text_named_params' => [['param_name' => 'pedido', 'example' => '1001']]],
    ];

    public function test_rejected_template_is_resubmitted_to_meta_and_pending_again(): void
    {
        [$owner, $channel] = $this->context();
        $template = $this->template($channel, TemplateStatus::Rejected);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['success' => true])]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/channels/{$channel->id}/templates/{$template->id}", [
            'category' => 'MARKETING',
            'components' => [self::BODY],
        ])->assertOk()->assertJsonPath('status', 'PENDING');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/meta-template')
            && $request['category'] === 'MARKETING'
            && $request['components'] === [self::BODY]
            && ! isset($request['name']));

        $template->refresh();
        $this->assertSame(TemplateStatus::Pending, $template->status);
        $this->assertSame(TemplateCategory::Marketing, $template->category);
        $this->assertNull($template->rejected_reason);
        $this->assertSame([self::BODY], $template->components);
    }

    public function test_approved_template_keeps_its_category(): void
    {
        [$owner, $channel] = $this->context();
        $template = $this->template($channel, TemplateStatus::Approved);
        Http::fake();
        Sanctum::actingAs($owner);

        $this->putJson("/api/channels/{$channel->id}/templates/{$template->id}", [
            'category' => 'MARKETING',
            'components' => [self::BODY],
        ])->assertUnprocessable()->assertJsonPath('message', 'Meta no deja cambiar la categoría de una plantilla aprobada.');

        Http::assertNothingSent();
    }

    public function test_template_in_review_cannot_be_edited(): void
    {
        [$owner, $channel] = $this->context();
        $template = $this->template($channel, TemplateStatus::Pending);
        Http::fake();
        Sanctum::actingAs($owner);

        $this->putJson("/api/channels/{$channel->id}/templates/{$template->id}", ['components' => [self::BODY]])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_meta_rejection_keeps_the_local_template(): void
    {
        [$owner, $channel] = $this->context();
        $template = $this->template($channel, TemplateStatus::Approved);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'Edit limit reached']], 400)]);
        Sanctum::actingAs($owner);

        $this->putJson("/api/channels/{$channel->id}/templates/{$template->id}", ['components' => [self::BODY]])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Meta rechazó la edición de la plantilla: Edit limit reached');

        $template->refresh();
        $this->assertSame(TemplateStatus::Approved, $template->status);
        $this->assertSame([['type' => 'BODY', 'text' => 'Hola']], $template->components);
    }

    public function test_numbered_variables_are_rejected_before_calling_meta(): void
    {
        [$owner, $channel] = $this->context();
        $template = $this->template($channel, TemplateStatus::Rejected);
        Http::fake();
        Sanctum::actingAs($owner);

        $this->putJson("/api/channels/{$channel->id}/templates/{$template->id}", [
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']],
        ])->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_user_without_create_permission_is_rejected(): void
    {
        [$owner, $channel] = $this->context();
        $member = User::factory()->create(['tenant_id' => $owner->tenant_id, 'role' => UserRole::EMPLOYEE]);
        $member->assignRole('Member');
        $channel->update(['user_id' => $member->id]);
        $template = $this->template($channel, TemplateStatus::Rejected);
        Http::fake();
        Sanctum::actingAs($member);

        $this->putJson("/api/channels/{$channel->id}/templates/{$template->id}", ['components' => [self::BODY]])
            ->assertForbidden();

        Http::assertNothingSent();
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
        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-waba-test',
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        return [$owner, Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ])];
    }

    private function template(Channel $channel, TemplateStatus $status): WhatsAppTemplate
    {
        return WhatsAppTemplate::create([
            'tenant_id' => $channel->tenant_id,
            'whatsapp_config_id' => $channel->whatsapp_config_id,
            'external_id' => 'meta-template',
            'name' => 'pedido_despachado',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => $status,
            'rejected_reason' => $status === TemplateStatus::Rejected ? 'INVALID_FORMAT' : null,
            'components' => [['type' => 'BODY', 'text' => 'Hola']],
        ]);
    }
}
