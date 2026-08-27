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

    public function test_paused_template_is_rejected_with_its_reason(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        Sanctum::actingAs($user);

        $template->update(['status' => TemplateStatus::Paused]);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Meta pausó esta plantilla por feedback negativo de los usuarios. Vas a poder volver a usarla cuando Meta la reactive.');
    }

    public function test_disabled_template_is_rejected_with_its_reason(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        Sanctum::actingAs($user);

        $template->update(['status' => TemplateStatus::Disabled]);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Meta deshabilitó esta plantilla de forma permanente. Creá una nueva para esta difusión.');
    }

    /**
     * Meta puede pausar la plantilla entre que se programa la campaña y el
     * momento del disparo. La campaña debe cortarse entera en vez de encolar un
     * job por destinatario que va a fallar de a uno.
     */
    public function test_scheduled_campaign_aborts_when_template_gets_paused_before_dispatch(): void
    {
        Queue::fake();
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        Sanctum::actingAs($user);

        $payload = $this->payload($channel, $template);
        $payload['launch'] = 'scheduled';
        $payload['scheduled_at'] = now()->addHour()->toIso8601String();
        $this->postJson('/api/broadcasts', $payload)->assertCreated();

        $campaign = BroadcastCampaign::firstOrFail();
        $campaign->update(['scheduled_at' => now()->subMinute()]);
        $template->update(['status' => TemplateStatus::Paused]);

        $this->artisan('broadcasts:dispatch-due')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(BroadcastStatus::Failed, $campaign->fresh()->status);

        $recipient = $campaign->recipients()->firstOrFail();
        $this->assertSame(BroadcastRecipientStatus::Failed, $recipient->status);
        $this->assertStringContainsString('Pausado', (string) $recipient->error);
    }

    /**
     * Meta no entrega plantillas de marketing a números de EE.UU. desde el
     * 2025-04-01. Enviarlos igual consume cupo del límite de 24h y el usuario
     * los ve como "enviados" sin que nadie los reciba.
     */
    public function test_marketing_broadcast_excludes_united_states_contacts(): void
    {
        Queue::fake();
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel, null, [], '5492235550101');
        $this->createConversation($user, $channel, null, [], '17866085755');
        // Canadá comparte el +1 pero sí recibe marketing: no debe excluirse.
        $this->createConversation($user, $channel, null, [], '12895567358');

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 2)
            ->assertJsonPath('data.excluded_us_count', 1);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertCreated()
            ->assertJsonPath('data.audience_count', 2);

        Queue::assertPushed(SendBroadcastMessageJob::class, 2);
    }

    /**
     * El tope de destinatarios debe contarse sobre los que pueden recibir el
     * mensaje. Si se aplicara antes de descartar los de EE.UU., una tanda
     * inicial de números estadounidenses consumiría el cupo y la campaña
     * saldría corta —o vacía— aun habiendo elegibles más adelante.
     */
    public function test_recipient_cap_counts_only_deliverable_contacts(): void
    {
        Queue::fake();
        config(['broadcasts.max_recipients' => 3]);
        [$user, $channel, $template] = $this->createSetup();

        // Los primeros en orden de ID son todos de EE.UU. y llenarían el tope.
        foreach (['17866085755', '13057812143', '12066409886', '13478451506'] as $usPhone) {
            $this->createConversation($user, $channel, null, [], $usPhone);
        }
        foreach (['5492235550101', '5492235550102', '5492235550103'] as $arPhone) {
            $this->createConversation($user, $channel, null, [], $arPhone);
        }

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 3)
            ->assertJsonPath('data.excluded_us_count', 4);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertCreated()
            ->assertJsonPath('data.audience_count', 3);

        Queue::assertPushed(SendBroadcastMessageJob::class, 3);
    }

    public function test_utility_broadcast_reaches_united_states_contacts(): void
    {
        Queue::fake();
        [$user, $channel, $template] = $this->createSetup();
        $template->update(['category' => TemplateCategory::Utility]);
        $this->createConversation($user, $channel, null, [], '17866085755');

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1)
            ->assertJsonPath('data.excluded_us_count', 0);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))->assertCreated();

        Queue::assertPushed(SendBroadcastMessageJob::class, 1);
    }

    public function test_marketing_broadcast_with_only_us_contacts_explains_why(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel, null, [], '17866085755');
        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Todos los contactos de esta audiencia tienen números de Estados Unidos, y Meta no entrega plantillas de marketing a ese país.');
    }

    /**
     * Última línea de defensa: una campaña programada pudo persistir
     * destinatarios de EE.UU. antes de que existiera el filtro, o la plantilla
     * pudo recategorizarse a marketing después de crearse la campaña.
     */
    public function test_job_refuses_to_send_marketing_to_a_us_number(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.no-deberia-enviarse']]])]);
        [$user, $channel, $template] = $this->createSetup();
        $conversation = $this->createConversation($user, $channel, null, [], '17866085755');

        Queue::fake();
        Sanctum::actingAs($user);
        $this->postJson('/api/broadcasts', $this->payload($channel, $template))->assertStatus(422);

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $user->tenant_id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => $user->id,
            'name' => 'Campaña vieja',
            'audience_filters' => [],
            'components' => [],
            'audience_count' => 1,
            'estimated_cost_usd' => 0.07,
            'interval_seconds' => 0,
            'scheduled_at' => now(),
        ]);
        $recipient = $campaign->recipients()->create([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
        ]);

        (new SendBroadcastMessageJob(
            $conversation->id,
            $template->id,
            [],
            $user->id,
            $user->tenant_id,
            $recipient->id,
        ))->handle(app(WhatsAppTemplateService::class));

        $recipient->refresh();
        $this->assertSame(BroadcastRecipientStatus::Failed, $recipient->status);
        $this->assertStringContainsString('Estados Unidos', (string) $recipient->error);
        Http::assertNothingSent();
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

    private function createConversation(User $user, Channel $channel, ?int $stageId = null, array $customData = [], ?string $phone = null): Conversation
    {
        $contact = Contact::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Contacto '.uniqid(),
            'phone' => $phone ?? '+54911'.random_int(10000000, 99999999),
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
