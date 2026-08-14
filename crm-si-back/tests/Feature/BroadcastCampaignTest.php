<?php

namespace Tests\Feature;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\BroadcastCampaign;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PipelineStage;
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
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BroadcastCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_applies_pipeline_and_contact_filters(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $stage = PipelineStage::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Seguimiento',
            'color' => '#00aa77',
            'sort_order' => 1,
        ]);

        $this->createConversation($user, $channel, $stage->id, ['city' => 'Mar del Plata']);
        $this->createConversation($user, $channel, $stage->id, ['city' => 'Buenos Aires']);
        $this->createConversation($user, $channel, null, ['city' => 'Mar del Plata']);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'pipeline_stage_id' => $stage->id,
            'custom_filters' => [[
                'field' => 'city',
                'operator' => 'equals',
                'value' => 'Mar del Plata',
            ]],
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1)
            ->assertJsonPath('data.estimated_cost_usd', 0.07);
    }

    public function test_send_now_persists_recipients_and_queues_one_job_per_contact(): void
    {
        Queue::fake();
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        $this->createConversation($user, $channel);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Promo agosto')
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.audience_count', 2)
            ->assertJsonPath('data.pending_count', 2);

        $campaign = BroadcastCampaign::firstOrFail();
        $this->assertCount(2, $campaign->recipients);
        $this->assertSame(BroadcastStatus::Processing, $campaign->status);
        Queue::assertPushed(SendBroadcastMessageJob::class, 2);
    }

    public function test_scheduled_campaign_waits_until_dispatch_command(): void
    {
        Queue::fake();
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        Sanctum::actingAs($user);

        $payload = $this->payload($channel, $template);
        $payload['launch'] = 'scheduled';
        $payload['scheduled_at'] = now()->addHour()->toIso8601String();

        $this->postJson('/api/broadcasts', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled');

        Queue::assertNothingPushed();

        $campaign = BroadcastCampaign::firstOrFail();
        $campaign->update(['scheduled_at' => now()->subMinute()]);
        $this->artisan('broadcasts:dispatch-due')->assertSuccessful();

        Queue::assertPushed(SendBroadcastMessageJob::class, 1);
        $this->assertSame(BroadcastStatus::Processing, $campaign->fresh()->status);
    }

    public function test_campaign_list_is_isolated_by_tenant(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        Sanctum::actingAs($user);
        Queue::fake();
        $this->postJson('/api/broadcasts', $this->payload($channel, $template))->assertCreated();

        [$otherUser] = $this->createSetup();
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/broadcasts')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_meta_failure_moves_sent_recipient_to_error(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.broadcast']]])]);
        [$user, $channel, $template] = $this->createSetup();
        $conversation = $this->createConversation($user, $channel);
        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))->assertCreated();
        $campaign = BroadcastCampaign::firstOrFail();
        $recipient = $campaign->recipients()->firstOrFail();

        (new SendBroadcastMessageJob(
            $conversation->id,
            $template->id,
            [],
            $user->id,
            $user->tenant_id,
            $recipient->id,
        ))->handle(app(WhatsAppTemplateService::class));

        $recipient->refresh();
        $this->assertSame(BroadcastRecipientStatus::Sent, $recipient->status);
        $recipient->message->markAsFailed('Meta rechazó el destino');

        $this->assertSame(BroadcastRecipientStatus::Failed, $recipient->fresh()->status);
        $this->assertSame(BroadcastStatus::Failed, $campaign->fresh()->status);
        $this->assertSame('0.00', $campaign->fresh()->actual_cost_usd);
    }

    /** @return array{0: User, 1: Channel, 2: WhatsAppTemplate} */
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

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0101',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp principal',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
        $template = WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $config->id,
            'external_id' => 'template-'.uniqid(),
            'name' => 'promo_agosto',
            'language' => 'es_AR',
            'category' => TemplateCategory::Marketing,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']],
            'synced_at' => now(),
        ]);

        return [$user, $channel, $template];
    }

    private function createConversation(User $user, Channel $channel, ?int $stageId = null, array $customData = []): Conversation
    {
        $contact = Contact::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Contacto '.uniqid(),
            'phone' => '+54911'.random_int(10000000, 99999999),
            'source' => 'whatsapp',
            'custom_data' => $customData,
        ]);

        return Conversation::create([
            'tenant_id' => $user->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'pipeline_stage_id' => $stageId,
            'status' => 'open',
        ]);
    }

    private function payload(Channel $channel, WhatsAppTemplate $template, array $filters = []): array
    {
        return [
            'name' => 'Promo agosto',
            'channel_id' => $channel->id,
            'template_id' => $template->id,
            'components' => [],
            'filters' => $filters,
            'launch' => 'now',
            'interval_seconds' => 15,
        ];
    }
}
