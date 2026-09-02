<?php

namespace Tests\Feature;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Enums\ChannelType;
use App\Enums\ContactFieldType;
use App\Enums\MarketingConsentStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Jobs\DispatchBroadcastCampaignJob;
use App\Jobs\SendBroadcastMessageJob;
use App\Models\BroadcastCampaign;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageInteraction;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\BroadcastConversationResolver;
use App\Services\WhatsAppMessageService;
use App\Services\WhatsAppTemplateService;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Carbon\CarbonImmutable;
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

    public function test_between_filters_date_field_within_inclusive_range(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'vencimiento',
            'label' => 'Vencimiento',
            'type' => ContactFieldType::Date,
            'display_order' => 0,
        ]);

        $this->createConversation($user, $channel, null, ['vencimiento' => '2026-09-01']);
        $this->createConversation($user, $channel, null, ['vencimiento' => '2026-09-07']);
        $this->createConversation($user, $channel, null, ['vencimiento' => '2026-08-31']);
        $this->createConversation($user, $channel, null, ['vencimiento' => '2026-09-08']);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'custom_filters' => [[
                'field' => 'vencimiento',
                'operator' => 'between',
                'value' => ['from' => '2026-09-01', 'to' => '2026-09-07'],
            ]],
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 2);
    }

    public function test_between_with_only_from_matches_open_ended_range(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'vencimiento',
            'label' => 'Vencimiento',
            'type' => ContactFieldType::Date,
            'display_order' => 0,
        ]);

        $this->createConversation($user, $channel, null, ['vencimiento' => '2026-09-10']);
        $this->createConversation($user, $channel, null, ['vencimiento' => '2026-09-05']);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'custom_filters' => [[
                'field' => 'vencimiento',
                'operator' => 'between',
                'value' => ['from' => '2026-09-09'],
            ]],
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1);
    }

    public function test_greater_or_equal_and_less_or_equal_on_number_field(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'ciclos_impagos',
            'label' => 'Ciclos impagos',
            'type' => ContactFieldType::Number,
            'display_order' => 0,
        ]);

        // El caso que un filtro textual rompería: "9" > "10" como string.
        $this->createConversation($user, $channel, null, ['ciclos_impagos' => 9]);
        $this->createConversation($user, $channel, null, ['ciclos_impagos' => 10]);
        $this->createConversation($user, $channel, null, ['ciclos_impagos' => 2]);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'custom_filters' => [[
                'field' => 'ciclos_impagos',
                'operator' => 'greater_or_equal',
                'value' => '5',
            ]],
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 2);
    }

    public function test_range_operator_on_text_field_is_ignored(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'notas',
            'label' => 'Notas',
            'type' => ContactFieldType::Text,
            'display_order' => 0,
        ]);

        $this->createConversation($user, $channel, null, ['notas' => 'zzz']);

        Sanctum::actingAs($user);

        // Un rango sobre un campo Text no whitelisteado se descarta entero: el
        // filtro no se aplica (no rompe la query) y el contacto sigue contando.
        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'custom_filters' => [[
                'field' => 'notas',
                'operator' => 'between',
                'value' => ['from' => 'aaa', 'to' => 'mmm'],
            ]],
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1);
    }

    public function test_malformed_number_value_does_not_break_estimate(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'ciclos_impagos',
            'label' => 'Ciclos impagos',
            'type' => ContactFieldType::Number,
            'display_order' => 0,
        ]);

        // Cargado a mano fuera de la validación normal (ej. vía psql).
        $this->createConversation($user, $channel, null, ['ciclos_impagos' => 'N/A']);
        $this->createConversation($user, $channel, null, ['ciclos_impagos' => 3]);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'custom_filters' => [[
                'field' => 'ciclos_impagos',
                'operator' => 'between',
                'value' => ['from' => '1', 'to' => '5'],
            ]],
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1);
    }

    public function test_store_rejects_range_filter_without_from_or_to(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'custom_filters' => [[
                'field' => 'vencimiento',
                'operator' => 'between',
                'value' => [],
            ]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filters.custom_filters.0.value']);
    }

    public function test_send_now_persists_recipients_and_queues_one_job_per_contact(): void
    {
        // fakeExcept: DispatchBroadcastCampaignJob corre real (encolado sync
        // en tests) y a su vez despacha SendBroadcastMessageJob, que sí queda
        // interceptado. store() ya no llama al dispatcher en línea —el
        // request solo encola el orquestador— porque con audiencias de miles
        // de contactos el fan-out sincrónico no entra en el timeout de
        // PHP-FPM (ver BroadcastCampaignController::store()).
        Queue::fakeExcept([DispatchBroadcastCampaignJob::class]);
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
        Queue::fakeExcept([DispatchBroadcastCampaignJob::class]);
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
        Queue::fakeExcept([DispatchBroadcastCampaignJob::class]);
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
        Queue::fakeExcept([DispatchBroadcastCampaignJob::class]);
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
        ))->handle(app(WhatsAppTemplateService::class), app(BroadcastConversationResolver::class));

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
        ))->handle(app(WhatsAppTemplateService::class), app(BroadcastConversationResolver::class));

        $recipient->refresh();
        $this->assertSame(BroadcastRecipientStatus::Sent, $recipient->status);
        $recipient->message->markAsFailed('Meta rechazó el destino');

        $this->assertSame(BroadcastRecipientStatus::Failed, $recipient->fresh()->status);
        $this->assertSame(BroadcastStatus::Failed, $campaign->fresh()->status);
        $this->assertSame('0.00', $campaign->fresh()->actual_cost_usd);
    }

    public function test_results_show_original_meta_failure_detail_without_code(): void
    {
        Queue::fake();
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))->assertCreated();
        $campaign = BroadcastCampaign::firstOrFail();
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->update([
            'status' => BroadcastRecipientStatus::Failed,
            'failure_code' => '130472',
            'failure_details' => [[
                'code' => 130472,
                'message' => "Failed to send message because this user's phone number is part of an experiment.",
                'error_data' => ['details' => "Failed to send message because this user's phone number is part of an experiment."],
            ]],
        ]);

        $this->getJson("/api/broadcasts/{$campaign->id}/recipients/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.failure.message', "Failed to send message because this user's phone number is part of an experiment.");
    }

    public function test_results_fall_back_to_persisted_error_when_meta_details_are_missing(): void
    {
        Queue::fake();
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))->assertCreated();
        $campaign = BroadcastCampaign::firstOrFail();
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->update([
            'status' => BroadcastRecipientStatus::Failed,
            'error' => '[130472] Failed to send message because this user\'s phone number is part of an experiment.',
        ]);

        $this->getJson("/api/broadcasts/{$campaign->id}/recipients/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.failure.message', "Failed to send message because this user's phone number is part of an experiment.");
    }

    public function test_text_reply_matches_recent_broadcast_across_local_and_utc_timestamps(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-28 21:38:34', 'America/Argentina/Buenos_Aires'));
        [$user, $channel, $template] = $this->createSetup();
        $conversation = $this->createConversation($user, $channel, phone: '5492235112208');
        $contact = $conversation->contact;

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $user->tenant_id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => $user->id,
            'name' => 'Prueba de interacción',
            'status' => BroadcastStatus::Completed,
            'audience_count' => 1,
            'scheduled_at' => now(),
            'results_tracking_version' => 1,
        ]);
        $outbound = Message::create([
            'tenant_id' => $user->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $user->id,
            'content' => 'Hola desde la difusión',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => 'wamid.broadcast-timezone',
        ]);
        $recipient = BroadcastRecipient::create([
            'broadcast_campaign_id' => $campaign->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'message_id' => $outbound->id,
            'status' => BroadcastRecipientStatus::Sent,
            'sent_at' => CarbonImmutable::parse('2026-08-29 00:37:48', 'UTC'),
        ]);

        app(WhatsAppMessageService::class)->processIncomingMessage([
            'value' => [
                'metadata' => ['phone_number_id' => $channel->whatsappConfig->phone_number_id],
                'contacts' => [['profile' => ['name' => $contact->name]]],
                'messages' => [[
                    'from' => $contact->phone,
                    'id' => 'wamid.generic-reply-timezone',
                    'timestamp' => (string) CarbonImmutable::parse('2026-08-29 00:38:34', 'UTC')->timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'Hola cómo estás?'],
                ]],
            ],
        ]);

        $interaction = MessageInteraction::where('broadcast_recipient_id', $recipient->id)->first();
        $this->assertNotNull($interaction);
        $this->assertSame('reply', $interaction->type);
        $this->assertSame('Hola cómo estás?', $interaction->content);
    }

    /**
     * El caso central del cambio: la audiencia deja de ser "conversaciones de
     * un canal" y pasa a ser "contactos del tenant", alcanzando a quien nunca
     * escribió. Sin consentimiento explícito caen en without_consent (ver
     * test_store_requires_acknowledgement_for_contacts_without_consent), así
     * que acá se les marca granted para aislar lo que este test mide.
     */
    public function test_audience_includes_contacts_without_any_conversation(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        $this->createContactWithoutConsent($user)->update(['marketing_consent_status' => MarketingConsentStatus::Granted]);
        $this->createContactWithoutConsent($user)->update(['marketing_consent_status' => MarketingConsentStatus::Granted]);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 3)
            ->assertJsonPath('data.contacts_without_conversation_count', 2);
    }

    public function test_audience_includes_contacts_whose_conversation_is_in_another_channel(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $otherChannelConfig = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0202',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
        $otherChannel = Channel::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp secundario',
            'status' => 'active',
            'whatsapp_config_id' => $otherChannelConfig->id,
        ]);
        $this->createConversation($user, $otherChannel);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1)
            ->assertJsonPath('data.contacts_without_conversation_count', 1);
    }

    public function test_contact_with_conversations_in_two_channels_receives_only_one_message(): void
    {
        Queue::fakeExcept([DispatchBroadcastCampaignJob::class]);
        [$user, $channel, $template] = $this->createSetup();
        $otherChannelConfig = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0303',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
        $otherChannel = Channel::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp secundario',
            'status' => 'active',
            'whatsapp_config_id' => $otherChannelConfig->id,
        ]);
        $conversation = $this->createConversation($user, $channel);
        Conversation::create([
            'tenant_id' => $user->tenant_id,
            'channel_id' => $otherChannel->id,
            'contact_id' => $conversation->contact_id,
            'status' => 'open',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertCreated()
            ->assertJsonPath('data.audience_count', 1);

        Queue::assertPushed(SendBroadcastMessageJob::class, 1);
    }

    public function test_duplicate_phone_numbers_are_deduplicated(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel, phone: '5492235550101');
        // Mismo número, escrito distinto: normaliza a la misma clave.
        Contact::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Duplicado',
            'phone' => '+54 9 223 555-0101',
            'source' => 'whatsapp',
            'marketing_consent_status' => MarketingConsentStatus::Granted,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1)
            ->assertJsonPath('data.excluded_duplicate_count', 1);
    }

    /**
     * Sin include_without_consent, el tope de max_recipients debe aplicarse
     * SOLO sobre la audiencia consented: un lote inicial de contactos
     * unknown (id bajo, más antiguos) no puede consumir el cupo ni cortar la
     * paginación antes de llegar a los contactos granted que sí importan
     * para esta request (ver BroadcastAudienceService::resolveForCategory).
     */
    public function test_recipient_cap_is_applied_only_to_the_audience_selected_for_this_request(): void
    {
        config(['broadcasts.max_recipients' => 3]);
        [$user, $channel, $template] = $this->createSetup();

        // Los primeros en orden de ID son unknown y, sin este fix, agotarían
        // el cupo antes de escanear los granted.
        for ($i = 0; $i < 5; $i++) {
            $this->createContactWithoutConsent($user);
        }
        $this->createConversation($user, $channel);
        $this->createConversation($user, $channel);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.consented_count', 2)
            ->assertJsonPath('data.without_consent_count', 3);
    }

    /**
     * pipeline_stage_id vive en Conversation, no en Contact: filtrar por
     * etapa excluye implícitamente a quienes no tienen conversación. El
     * estimate debe anunciarlo, o el usuario ve una audiencia mucho menor a
     * la del tenant sin entender por qué (ver BroadcastAudienceService).
     */
    public function test_pipeline_stage_filter_excludes_contacts_without_conversations(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $stage = PipelineStage::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Seguimiento',
            'color' => '#00aa77',
            'sort_order' => 1,
        ]);
        $this->createConversation($user, $channel, $stage->id);
        $this->createContactWithoutConsent($user)->update(['marketing_consent_status' => MarketingConsentStatus::Granted]);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'pipeline_stage_id' => $stage->id,
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1)
            ->assertJsonPath('data.total_contacts_with_phone', 2)
            ->assertJsonPath('data.filters_applied.pipeline_stage_restricts_to_existing_conversations', true);
    }

    /**
     * Los tags están mayoritariamente en Conversation en producción, pero el
     * filtro debe alcanzar también a los contactos sin conversación que
     * tengan el tag directo sobre Contact.
     */
    public function test_tag_filter_reaches_contacts_without_conversations(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $tag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'VIP', 'slug' => 'vip']);
        $contact = $this->createContactWithoutConsent($user);
        $contact->update(['marketing_consent_status' => MarketingConsentStatus::Granted]);
        $contact->tags()->attach($tag->id, ['tenant_id' => $user->tenant_id]);
        $this->createConversation($user, $channel);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'tag_ids' => [$tag->id],
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1);
    }

    public function test_excluded_tags_take_priority_across_contacts_and_conversations(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $includedTag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'Clientes', 'slug' => 'clientes']);
        $excludedTag = Tag::create(['tenant_id' => $user->tenant_id, 'name' => 'No contactar', 'slug' => 'no-contactar']);

        $included = $this->createContactWithoutConsent($user);
        $included->update(['marketing_consent_status' => MarketingConsentStatus::Granted]);
        $included->tags()->attach($includedTag->id, ['tenant_id' => $user->tenant_id]);

        $excludedDirectly = $this->createContactWithoutConsent($user);
        $excludedDirectly->update(['marketing_consent_status' => MarketingConsentStatus::Granted]);
        $excludedDirectly->tags()->attach([$includedTag->id, $excludedTag->id], ['tenant_id' => $user->tenant_id]);

        $excludedByConversation = $this->createConversation($user, $channel);
        $excludedByConversation->contact->tags()->attach($includedTag->id, ['tenant_id' => $user->tenant_id]);
        $excludedByConversation->tags()->attach($excludedTag->id, ['tenant_id' => $user->tenant_id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template, [
            'tag_ids' => [$includedTag->id],
            'excluded_tag_ids' => [$excludedTag->id],
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1);
    }

    /**
     * El backfill de la migración solo cubre mensajes inbound que ya
     * existían al migrar. Sin esto, un contacto nuevo que escribe por primera
     * vez después del deploy —o uno existente que recién ahora inicia
     * contacto— quedaría en unknown para siempre y una difusión de marketing
     * normal lo excluiría igual que a alguien que nunca escribió (ver
     * WhatsAppMessageService::grantConsentFromInboundMessage()).
     */
    public function test_inbound_message_grants_consent_to_a_previously_unknown_contact(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $contact = $this->createContactWithoutConsent($user, phone: '5492235559999');
        $this->assertNull($contact->fresh()->marketing_consent_status);

        app(WhatsAppMessageService::class)->processIncomingMessage([
            'value' => [
                'metadata' => ['phone_number_id' => $channel->whatsappConfig->phone_number_id],
                'contacts' => [['profile' => ['name' => $contact->name]]],
                'messages' => [[
                    'from' => $contact->phone,
                    'id' => 'wamid.first-contact-'.uniqid(),
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'Hola, quiero info'],
                ]],
            ],
        ]);

        $contact->refresh();
        $this->assertSame(MarketingConsentStatus::Granted, $contact->marketing_consent_status);
        $this->assertSame('inbound_message', $contact->marketing_consent_source);
        $this->assertNotNull($contact->marketing_consent_at);

        Sanctum::actingAs($user);
        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1)
            ->assertJsonPath('data.without_consent_count', 0);
    }

    public function test_inbound_message_does_not_override_an_existing_denied_consent(): void
    {
        [$user, $channel] = $this->createSetup();
        $contact = $this->createContactWithoutConsent($user, phone: '5492235558888');
        $contact->update(['marketing_consent_status' => MarketingConsentStatus::Denied]);

        app(WhatsAppMessageService::class)->processIncomingMessage([
            'value' => [
                'metadata' => ['phone_number_id' => $channel->whatsappConfig->phone_number_id],
                'contacts' => [['profile' => ['name' => $contact->name]]],
                'messages' => [[
                    'from' => $contact->phone,
                    'id' => 'wamid.denied-writes-again-'.uniqid(),
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'text',
                    'text' => ['body' => 'Hola'],
                ]],
            ],
        ]);

        $this->assertSame(MarketingConsentStatus::Denied, $contact->fresh()->marketing_consent_status);
    }

    public function test_denied_contact_never_enters_the_audience(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        $denied = $this->createContactWithoutConsent($user);
        $denied->update(['marketing_consent_status' => MarketingConsentStatus::Denied]);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1);

        $this->postJson('/api/broadcasts', array_merge($this->payload($channel, $template), [
            'include_without_consent' => true,
            'acknowledge_consent_risk' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.audience_count', 1);
    }

    public function test_utility_template_does_not_filter_by_consent(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $template->update(['category' => TemplateCategory::Utility]);
        $this->createContactWithoutConsent($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1)
            ->assertJsonPath('data.without_consent_count', 0);
    }

    /**
     * Sin include_without_consent, estimate() debe reportar EXACTAMENTE la
     * misma audiencia que store() va a usar: audience_count, costo,
     * messaging_limit y contacts_without_conversation_count no deben incluir
     * a los contactos sin consentimiento, aunque without_consent_count los
     * siga informando por separado. De lo contrario el front muestra
     * destinatarios que store() excluye y fuerza un reconocimiento de riesgo
     * que el usuario no pidió (ver BroadcastCampaignController::estimate()).
     */
    public function test_estimate_excludes_contacts_without_consent_unless_flag_is_set(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        $this->createContactWithoutConsent($user);
        $this->createContactWithoutConsent($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts/estimate', $this->payload($channel, $template))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 1)
            ->assertJsonPath('data.consented_count', 1)
            ->assertJsonPath('data.without_consent_count', 2)
            ->assertJsonPath('data.contacts_without_conversation_count', 0)
            ->assertJsonPath('data.estimated_cost_usd', 0.07)
            ->assertJsonPath('data.capped', false);

        $this->postJson('/api/broadcasts/estimate', array_merge($this->payload($channel, $template), [
            'include_without_consent' => true,
        ]))
            ->assertOk()
            ->assertJsonPath('data.audience_count', 3)
            ->assertJsonPath('data.consented_count', 1)
            ->assertJsonPath('data.without_consent_count', 2)
            ->assertJsonPath('data.contacts_without_conversation_count', 2);
    }

    public function test_store_rejects_contacts_without_consent_unless_included(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createContactWithoutConsent($user);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Todos los contactos de esta audiencia no tienen consentimiento registrado para marketing. Marcá "incluir sin consentimiento" para enviarles igual.');
    }

    public function test_store_requires_acknowledgement_for_contacts_without_consent(): void
    {
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        $this->createContactWithoutConsent($user);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/broadcasts', array_merge($this->payload($channel, $template), [
            'include_without_consent' => true,
        ]))
            ->assertStatus(422);

        $response->assertJsonPath('consent_warning.without_consent_count', 1);
        $response->assertJsonPath('consent_warning.consented_count', 1);

        $accepted = $this->postJson('/api/broadcasts', array_merge($this->payload($channel, $template), [
            'include_without_consent' => true,
            'acknowledge_consent_risk' => true,
        ]))
            ->assertCreated();

        $accepted->assertJsonPath('data.audience_count', 2);

        $campaign = BroadcastCampaign::firstOrFail();
        $this->assertSame($user->id, $campaign->consent_warning_accepted_by);
        $this->assertNotNull($campaign->consent_warning_accepted_at);
        $this->assertSame(1, $campaign->without_consent_count);
    }

    public function test_store_requires_acknowledgement_above_confirmation_threshold(): void
    {
        config(['broadcasts.confirmation_threshold' => 1]);
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);
        $this->createConversation($user, $channel);

        Sanctum::actingAs($user);

        $this->postJson('/api/broadcasts', $this->payload($channel, $template))
            ->assertStatus(422)
            ->assertJsonPath('audience_count', 2);

        $this->postJson('/api/broadcasts', array_merge($this->payload($channel, $template), [
            'acknowledge_audience_size' => true,
        ]))->assertCreated();
    }

    public function test_store_rejects_interval_that_would_take_too_long(): void
    {
        config(['broadcasts.max_campaign_duration_seconds' => 100]);
        [$user, $channel, $template] = $this->createSetup();
        $this->createConversation($user, $channel);

        Sanctum::actingAs($user);

        $payload = $this->payload($channel, $template);
        $payload['interval_seconds'] = 120;

        $this->postJson('/api/broadcasts', $payload)->assertStatus(422);
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

    private static int $nextPhoneSuffix = 10000000;

    /**
     * Crea una conversación con un contacto que YA dio consentimiento
     * (granted): representa al contacto que escribió al negocio, que es
     * exactamente el criterio del backfill real
     * (2026_08_30_090000_add_marketing_consent_to_contacts_table). La mayoría
     * de los tests de este archivo prueba audiencia/filtros/envío, no
     * consentimiento, así que el default evita que todos tengan que declarar
     * el acknowledge para pasar. Los tests de consentimiento en sí usan
     * createContactWithoutConsent().
     */
    private function createConversation(User $user, Channel $channel, ?int $stageId = null, array $customData = [], ?string $phone = null): Conversation
    {
        $contact = Contact::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Contacto '.uniqid(),
            'phone' => $phone ?? '+5491'.self::$nextPhoneSuffix++,
            'source' => 'whatsapp',
            'custom_data' => $customData,
            'marketing_consent_status' => MarketingConsentStatus::Granted,
            'marketing_consent_source' => 'inbound_message',
            'marketing_consent_at' => now(),
        ]);

        return Conversation::create([
            'tenant_id' => $user->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'pipeline_stage_id' => $stageId,
            'status' => 'open',
        ]);
    }

    /** Contacto sin conversación y sin consentimiento evaluado (unknown). */
    private function createContactWithoutConsent(User $user, ?string $phone = null): Contact
    {
        return Contact::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Contacto sin consentimiento '.uniqid(),
            'phone' => $phone ?? '+5491'.self::$nextPhoneSuffix++,
            'source' => 'whatsapp',
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
