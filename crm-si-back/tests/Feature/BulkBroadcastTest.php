<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastConversationResolver;
use App\Services\WhatsAppTemplateService;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BulkBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_broadcast_queues_one_job_per_conversation(): void
    {
        Queue::fake();

        [$user, $config] = $this->createTenantWithWhatsAppChannel();
        $conversations = $this->createConversations($user->tenant_id, $config, 3);
        $template = $this->createApprovedTemplate($user->tenant_id, $config);

        Sanctum::actingAs($user);

        $this->postJson('/api/conversations/bulk-broadcast', [
            'ids' => $conversations->pluck('id')->all(),
            'template_id' => $template->id,
            'components' => [],
        ])
            ->assertOk()
            ->assertJson(['queued' => 3, 'failed' => 0]);

        Queue::assertPushed(SendBroadcastMessageJob::class, 3);
    }

    public function test_bulk_broadcast_rejects_mixed_channels(): void
    {
        Queue::fake();

        [$user, $config] = $this->createTenantWithWhatsAppChannel();
        $conversationA = $this->createConversations($user->tenant_id, $config, 1)->first();

        // Segundo canal WhatsApp del mismo tenant con otro número
        $otherConfig = WhatsAppConfig::create([
            'phone_number_id' => '999999999',
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-other',
            'bussines_token' => Crypt::encryptString('other-token'),
        ]);
        $conversationB = $this->createConversations($user->tenant_id, $otherConfig, 1)->first();

        $template = $this->createApprovedTemplate($user->tenant_id, $config);

        Sanctum::actingAs($user);

        $this->postJson('/api/conversations/bulk-broadcast', [
            'ids' => [$conversationA->id, $conversationB->id],
            'template_id' => $template->id,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_bulk_broadcast_rejects_unapproved_template(): void
    {
        Queue::fake();

        [$user, $config] = $this->createTenantWithWhatsAppChannel();
        $conversations = $this->createConversations($user->tenant_id, $config, 2);
        $template = $this->createApprovedTemplate($user->tenant_id, $config);
        $template->update(['status' => TemplateStatus::Pending]);

        Sanctum::actingAs($user);

        $this->postJson('/api/conversations/bulk-broadcast', [
            'ids' => $conversations->pluck('id')->all(),
            'template_id' => $template->id,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_bulk_broadcast_rejects_template_from_another_channel(): void
    {
        Queue::fake();

        [$user, $config] = $this->createTenantWithWhatsAppChannel();
        $conversations = $this->createConversations($user->tenant_id, $config, 2);

        $otherConfig = WhatsAppConfig::create([
            'phone_number_id' => '888888888',
            'display_phone_number' => '+54 9 11 1111-1111',
            'waba_id' => 'waba-foreign',
            'bussines_token' => Crypt::encryptString('foreign-token'),
        ]);
        $foreignTemplate = $this->createApprovedTemplate($user->tenant_id, $otherConfig);

        Sanctum::actingAs($user);

        $this->postJson('/api/conversations/bulk-broadcast', [
            'ids' => $conversations->pluck('id')->all(),
            'template_id' => $foreignTemplate->id,
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_job_does_not_send_across_tenants(): void
    {
        Http::fake();

        [$user, $config] = $this->createTenantWithWhatsAppChannel();
        $conversation = $this->createConversations($user->tenant_id, $config, 1)->first();
        $template = $this->createApprovedTemplate($user->tenant_id, $config);

        $foreignTenant = Tenant::create(['name' => 'Other Corp']);

        // El job corre con un tenantId ajeno: no debe encontrar recursos ni llamar a Meta.
        (new SendBroadcastMessageJob(
            $conversation->id,
            $template->id,
            [],
            $user->id,
            $foreignTenant->id,
        ))->handle(app(WhatsAppTemplateService::class), app(BroadcastConversationResolver::class));

        Http::assertNothingSent();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_job_aborts_when_template_belongs_to_other_channel(): void
    {
        Http::fake();

        [$user, $config] = $this->createTenantWithWhatsAppChannel();
        $conversation = $this->createConversations($user->tenant_id, $config, 1)->first();

        $otherConfig = WhatsAppConfig::create([
            'phone_number_id' => '777777777',
            'display_phone_number' => '+54 9 11 2222-2222',
            'waba_id' => 'waba-mismatch',
            'bussines_token' => Crypt::encryptString('mismatch-token'),
        ]);
        $foreignTemplate = $this->createApprovedTemplate($user->tenant_id, $otherConfig);

        (new SendBroadcastMessageJob(
            $conversation->id,
            $foreignTemplate->id,
            [],
            $user->id,
            $user->tenant_id,
        ))->handle(app(WhatsAppTemplateService::class), app(BroadcastConversationResolver::class));

        Http::assertNothingSent();
        $this->assertSame(0, $conversation->messages()->count());
    }

    public function test_job_sends_template_and_persists_external_id(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST123']],
            ]),
        ]);

        [$user, $config] = $this->createTenantWithWhatsAppChannel();
        $conversation = $this->createConversations($user->tenant_id, $config, 1)->first();
        $template = $this->createApprovedTemplate($user->tenant_id, $config);

        (new SendBroadcastMessageJob(
            $conversation->id,
            $template->id,
            [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Juan'], ['type' => 'text', 'text' => '42']]]],
            $user->id,
            $user->tenant_id,
        ))->handle(app(WhatsAppTemplateService::class), app(BroadcastConversationResolver::class));

        $message = $conversation->messages()->first();
        $this->assertNotNull($message);
        $this->assertSame('wamid.TEST123', $message->external_id);

        $conversation->refresh();
        $this->assertFalse($conversation->ai_autoreply_enabled);
    }

    public function test_job_resolves_personalization_tokens_per_contact(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TOKENS']],
            ]),
        ]);

        [$user, $config] = $this->createTenantWithWhatsAppChannel();
        $conversation = $this->createConversations($user->tenant_id, $config, 1)->first();
        $template = $this->createApprovedTemplate($user->tenant_id, $config);

        (new SendBroadcastMessageJob(
            $conversation->id,
            $template->id,
            [['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => '{{nombre}}'],
                ['type' => 'text', 'text' => 'valor fijo'],
            ]]],
            $user->id,
            $user->tenant_id,
        ))->handle(app(WhatsAppTemplateService::class), app(BroadcastConversationResolver::class));

        $contactName = $conversation->contact->name;

        Http::assertSent(function ($request) use ($contactName) {
            $params = $request->data()['template']['components'][0]['parameters'] ?? [];

            return ($params[0]['text'] ?? null) === $contactName
                && ($params[1]['text'] ?? null) === 'valor fijo';
        });
    }

    /**
     * @return array{0: User, 1: WhatsAppConfig}
     */
    private function createTenantWithWhatsAppChannel(): array
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

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'display_phone_number' => '+54 9 223 511-2208',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        return [$user, $config];
    }

    private function createConversations(int $tenantId, WhatsAppConfig $config, int $count)
    {
        $channel = Channel::create([
            'tenant_id' => $tenantId,
            'user_id' => User::where('tenant_id', $tenantId)->first()->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Channel '.$config->phone_number_id,
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);

        return collect(range(1, $count))->map(function (int $i) use ($tenantId, $channel) {
            $contact = Contact::create([
                'tenant_id' => $tenantId,
                'name' => "Contact {$channel->id}-{$i}",
                'phone' => '+54911000000'.$i,
                'source' => 'whatsapp',
            ]);

            return Conversation::create([
                'tenant_id' => $tenantId,
                'channel_id' => $channel->id,
                'contact_id' => $contact->id,
                'status' => 'open',
            ]);
        });
    }

    private function createApprovedTemplate(int $tenantId, WhatsAppConfig $config): WhatsAppTemplate
    {
        return WhatsAppTemplate::create([
            'tenant_id' => $tenantId,
            'whatsapp_config_id' => $config->id,
            'external_id' => 'ext-'.$config->id.'-'.uniqid(),
            'name' => 'order_ready',
            'language' => 'es_AR',
            'category' => TemplateCategory::Utility,
            'status' => TemplateStatus::Approved,
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => 'Hola {{1}}, tu pedido #{{2}} está listo.',
                ],
            ],
            'synced_at' => now(),
        ]);
    }
}
