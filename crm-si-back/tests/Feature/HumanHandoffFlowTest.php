<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\NotificationType;
use App\Enums\SenderType;
use App\Http\Controllers\WhatsAppController;
use App\Jobs\SendHumanHandoffNotificationJob;
use App\Models\AiConfig;
use App\Models\AiSchedulingConfig;
use App\Models\Conversation;
use App\Models\GoogleCalendarConnection;
use App\Models\HandoffNotificationAttempt;
use App\Models\HumanHandoff;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\AiReplyService;
use App\Services\HumanHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsNotificationWorkspace;
use Tests\TestCase;

class HumanHandoffFlowTest extends TestCase
{
    use BuildsNotificationWorkspace;
    use RefreshDatabase;

    private const ALERTS_PHONE_ID = 'ALERTS_PHONE_1';

    private Tenant $tenant;

    private User $owner;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'services.si_crm_alerts.enabled' => true,
            'services.si_crm_alerts.phone_number_id' => self::ALERTS_PHONE_ID,
            'services.si_crm_alerts.access_token' => 'alerts-token',
        ]);

        $this->tenant = $this->createWorkspace();
        $this->owner = $this->addMember($this->tenant);
        $this->conversation = $this->createConversation($this->tenant, $this->owner);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    // --- Creación y asignación -------------------------------------------------

    public function test_existing_assignee_keeps_the_conversation_over_channel_responsible(): void
    {
        Queue::fake();
        $current = $this->addMember($this->tenant, 'Member');
        $responsible = $this->addMember($this->tenant, 'Member');
        $this->conversation->channel->update(['handoff_responsible_user_id' => $responsible->id]);
        $this->conversation->update(['assigned_to' => $current->id, 'ai_autoreply_enabled' => true]);

        $handoff = app(HumanHandoffService::class)->create($this->conversation, null, 'customer_requested_human', 'Quiere hablar con ventas.');

        $this->assertSame($current->id, $handoff->assigned_to);
        $this->assertSame($current->id, $this->conversation->fresh()->assigned_to);
        $this->assertFalse($this->conversation->fresh()->ai_autoreply_enabled);
        Queue::assertPushed(SendHumanHandoffNotificationJob::class);
    }

    public function test_unassigned_conversation_goes_to_responsible_then_owner(): void
    {
        Queue::fake();
        $responsible = $this->addMember($this->tenant, 'Member');
        $this->conversation->channel->update(['handoff_responsible_user_id' => $responsible->id]);

        $handoff = app(HumanHandoffService::class)->create($this->conversation);
        $this->assertSame($responsible->id, $handoff->assigned_to);

        $handoff->update(['status' => 'resolved']);
        $this->conversation->update(['assigned_to' => null]);
        $this->conversation->channel->update(['handoff_responsible_user_id' => null]);

        $second = app(HumanHandoffService::class)->create($this->conversation->fresh());
        $this->assertSame($this->owner->id, $second->assigned_to);
    }

    public function test_active_handoff_is_reused(): void
    {
        Queue::fake();
        $service = app(HumanHandoffService::class);

        $first = $service->create($this->conversation);
        $second = $service->create($this->conversation);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, HumanHandoff::count());
    }

    public function test_assignee_gets_in_app_notification(): void
    {
        Queue::fake();

        app(HumanHandoffService::class)->create($this->conversation, null, 'customer_requested_human', 'Quiere un presupuesto.');

        $notification = UserNotification::where('user_id', $this->owner->id)->sole();
        $this->assertSame(NotificationType::HANDOFF_REQUESTED->value, $notification->type instanceof NotificationType ? $notification->type->value : $notification->type);
        $this->assertSame('Quiere un presupuesto.', $notification->data['summary']);
    }

    // --- Alerta al número del canal ---------------------------------------------

    public function test_alert_goes_to_channel_number_when_on_business_app(): void
    {
        $this->connectWhatsApp(isOnBizApp: true);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.ALERT1']]])]);

        $handoff = $this->createHandoffWithoutJob('Quiere hablar con ventas.');
        (new SendHumanHandoffNotificationJob($handoff->id))->handle();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/'.self::ALERTS_PHONE_ID.'/messages')
            && $request['to'] === '541199998888'
            && $request['template']['components'][0]['parameters'][2]['text'] === 'Quiere hablar con ventas.'
            && $request['template']['components'][1]['parameters'][0]['text'] === (string) $this->conversation->id);

        $attempt = HandoffNotificationAttempt::sole();
        $this->assertSame('channel', $attempt->destination_type);
        $this->assertSame('sent', $attempt->status);
        $this->assertSame('wamid.ALERT1', $attempt->external_id);
    }

    public function test_alert_is_skipped_for_cloud_only_number(): void
    {
        $this->connectWhatsApp(isOnBizApp: false);
        Http::fake();

        $handoff = $this->createHandoffWithoutJob();
        (new SendHumanHandoffNotificationJob($handoff->id))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, HandoffNotificationAttempt::count());
    }

    public function test_alert_is_skipped_when_channel_switch_is_off(): void
    {
        $this->connectWhatsApp(isOnBizApp: true);
        $this->conversation->channel->update(['handoff_whatsapp_alert_enabled' => false]);
        Http::fake();

        $handoff = $this->createHandoffWithoutJob();
        (new SendHumanHandoffNotificationJob($handoff->id))->handle();

        Http::assertNothingSent();
    }

    public function test_failed_send_is_recorded(): void
    {
        $this->connectWhatsApp(isOnBizApp: true);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'template not found']], 400)]);

        $handoff = $this->createHandoffWithoutJob();
        (new SendHumanHandoffNotificationJob($handoff->id))->handle();

        $attempt = HandoffNotificationAttempt::sole();
        $this->assertSame('failed', $attempt->status);
        $this->assertStringContainsString('template not found', $attempt->error);
    }

    // --- Webhook del número de alertas ------------------------------------------

    public function test_alert_statuses_arrive_through_main_webhook_with_precedence(): void
    {
        $handoff = $this->createHandoffWithoutJob();
        $attempt = HandoffNotificationAttempt::create([
            'human_handoff_id' => $handoff->id, 'destination_type' => 'channel', 'phone' => '541199998888',
            'status' => 'sent', 'external_id' => 'wamid.ALERT1',
        ]);

        $this->alertsWebhook('read');
        $this->alertsWebhook('delivered');

        $attempt->refresh();
        $this->assertSame('read', $attempt->status);
        $this->assertNotNull($attempt->delivered_at);
        $this->assertNotNull($attempt->read_at);

        $this->alertsWebhook('failed');
        $this->assertSame('read', $attempt->fresh()->status);
    }

    public function test_unsigned_webhook_for_alerts_number_is_dropped(): void
    {
        Queue::fake();
        config(['services.facebook.app_secret' => 'app-secret']);

        $this->postWebhook(self::ALERTS_PHONE_ID, signature: 'sha256=forged')->assertOk();
        $this->assertSame(0, WhatsAppWebhookReceipt::count());

        $this->postWebhook(self::ALERTS_PHONE_ID, signature: null)->assertOk();
        $this->assertSame(1, WhatsAppWebhookReceipt::count());
    }

    public function test_unsigned_webhook_for_tenant_channel_is_still_accepted(): void
    {
        Queue::fake();
        config(['services.facebook.app_secret' => 'app-secret']);

        $this->postWebhook('TENANT_PHONE_1', signature: 'sha256=forged')->assertOk();

        $this->assertSame(1, WhatsAppWebhookReceipt::count());
    }

    // --- Tomar ------------------------------------------------------------------

    public function test_any_user_who_can_reply_can_take_and_becomes_assignee(): void
    {
        Queue::fake();
        $handoff = app(HumanHandoffService::class)->create($this->conversation);
        $admin = $this->addMember($this->tenant);
        Sanctum::actingAs($admin);

        $this->postJson("/api/conversations/{$this->conversation->id}/human-handoff/acknowledge")
            ->assertOk()
            ->assertJsonPath('data.status', 'acknowledged')
            ->assertJsonPath('data.assigned_to', $admin->id);

        $this->assertSame($admin->id, $this->conversation->fresh()->assigned_to);
        $this->assertSame('acknowledged', $handoff->fresh()->status);
    }

    public function test_user_who_cannot_touch_the_conversation_cannot_take(): void
    {
        Queue::fake();
        app(HumanHandoffService::class)->create($this->conversation);
        $outsider = $this->addMember($this->tenant, 'Member');
        Sanctum::actingAs($outsider);

        $this->postJson("/api/conversations/{$this->conversation->id}/human-handoff/acknowledge")
            ->assertForbidden();
    }

    // --- Cierre -----------------------------------------------------------------

    public function test_outbound_human_message_resolves_handoff_including_phone_echoes(): void
    {
        Queue::fake();
        $handoff = app(HumanHandoffService::class)->create($this->conversation);

        // Echo de coexistence: el negocio respondió desde el celular.
        $this->createMessage(SenderType::USER, MessageDirection::OUTBOUND);

        $this->assertSame('resolved', $handoff->fresh()->status);
        $this->assertNotNull($handoff->fresh()->resolved_at);
    }

    public function test_bot_and_customer_messages_do_not_resolve_handoff(): void
    {
        Queue::fake();
        $handoff = app(HumanHandoffService::class)->create($this->conversation);

        $this->createMessage(SenderType::SYSTEM, MessageDirection::OUTBOUND);
        $this->createMessage(SenderType::CONTACT, MessageDirection::INBOUND);

        $this->assertSame('pending', $handoff->fresh()->status);
    }

    public function test_message_older_than_handoff_does_not_resolve_it(): void
    {
        Queue::fake();
        $handoff = app(HumanHandoffService::class)->create($this->conversation);

        // Historial importado: llega ahora, pero con su fecha original.
        $this->createMessage(SenderType::USER, MessageDirection::OUTBOUND, now()->subDay());

        $this->assertSame('pending', $handoff->fresh()->status);
    }

    // --- Interruptor por tenant -------------------------------------------------

    public function test_tenant_switch_off_does_not_offer_the_handoff_tool(): void
    {
        $config = AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => AiProvider::CLAUDE,
            'model' => 'claude-sonnet-5-5',
            'enabled' => true,
            'human_handoff_enabled' => false,
        ]);
        $config->setEncryptedApiKey('sk-test');
        $this->createMessage(SenderType::CONTACT, MessageDirection::INBOUND);

        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hola']], 'stop_reason' => 'end_turn',
            'model' => 'claude-sonnet-5-5', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $service = app(AiReplyService::class);
        $service->decide($this->conversation, $config->fresh());
        $config->update(['human_handoff_enabled' => true]);
        $service->decide($this->conversation, $config->fresh());

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(2, $sent);
        $this->assertFalse(isset($sent[0]['tools']));
        $this->assertSame('request_human_handoff', $sent[1]['tools'][0]['name']);
    }

    /** "Quiero cambiar mi turno" no se deriva antes de que el bot vea su agenda. */
    public function test_handoff_decision_knows_the_agenda_when_scheduling_is_enabled(): void
    {
        $config = AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => AiProvider::CLAUDE,
            'model' => 'claude-sonnet-5-5',
            'enabled' => true,
        ]);
        $config->setEncryptedApiKey('sk-test');
        AiSchedulingConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'enabled' => true,
            'mode' => 'approval',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'default_user_id' => $this->owner->id,
        ]);
        GoogleCalendarConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'google_email' => 'owner@example.com',
            'access_token' => 'placeholder',
            'refresh_token' => 'placeholder',
            'token_expires_at' => now()->addYears(10),
            'status' => 'connected',
        ]);
        $this->createMessage(SenderType::CONTACT, MessageDirection::INBOUND);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'Hola']], 'stop_reason' => 'end_turn',
            'model' => 'claude-sonnet-5-5', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        app(AiReplyService::class)->decide($this->conversation, $config->fresh());

        $decision = Http::recorded()->first()[0];
        $this->assertSame('request_human_handoff', $decision['tools'][0]['name']);
        $this->assertStringContainsString('## Agenda de turnos', json_encode($decision['system'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('reprogramar_turno', json_encode($decision['system'], JSON_UNESCAPED_UNICODE));
    }

    // --- Configuración del canal ------------------------------------------------

    public function test_channel_handoff_settings_can_be_updated(): void
    {
        $agent = $this->addMember($this->tenant, 'Member');
        $this->conversation->channel->users()->attach($agent->id);
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/channels/{$this->conversation->channel_id}", [
            'handoff_responsible_user_id' => $agent->id,
            'handoff_whatsapp_alert_enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.handoff_responsible_user_id', $agent->id)
            ->assertJsonPath('data.handoff_whatsapp_alert_enabled', false);
    }

    public function test_channel_responsible_must_belong_to_the_channel(): void
    {
        $stranger = $this->addMember($this->tenant, 'Member');
        Sanctum::actingAs($this->owner);

        $this->patchJson("/api/channels/{$this->conversation->channel_id}", [
            'handoff_responsible_user_id' => $stranger->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('handoff_responsible_user_id');
    }

    // --- Helpers ----------------------------------------------------------------

    private function connectWhatsApp(bool $isOnBizApp): void
    {
        $config = WhatsAppConfig::create([
            'phone_number_id' => 'TENANT_PHONE_1',
            'display_phone_number' => '+54 9 11 9999-8888',
            'waba_id' => 'WABA_TEST',
            'bussines_token' => Crypt::encryptString('token-test'),
            'is_on_biz_app' => $isOnBizApp,
        ]);
        $this->conversation->channel->update(['whatsapp_config_id' => $config->id]);
    }

    private function createHandoffWithoutJob(?string $summary = 'El cliente pidió hablar con una persona.'): HumanHandoff
    {
        Queue::fake();

        return app(HumanHandoffService::class)->create($this->conversation->fresh(), null, 'customer_requested_human', $summary);
    }

    private function createMessage(SenderType $sender, MessageDirection $direction, $createdAt = null): Message
    {
        $message = new Message([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => $sender,
            'sender_id' => $sender === SenderType::CONTACT ? $this->conversation->contact_id : $this->owner->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => $direction,
        ]);
        if ($createdAt) {
            $message->created_at = $createdAt;
        }
        $message->save();

        return $message;
    }

    /** $signature null = firma válida calculada con el app secret. */
    private function postWebhook(string $phoneNumberId, ?string $signature)
    {
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'WABA_1',
            'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => $phoneNumberId],
                'statuses' => [['id' => 'wamid.X', 'status' => 'delivered', 'timestamp' => '1']],
            ]]],
        ]]]);

        return $this->call('POST', '/api/whatsapp-webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }

    private function alertsWebhook(string $status): void
    {
        app(WhatsAppController::class)->processQueuedWebhookChange('ALERTS_WABA', [
            'field' => 'messages',
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['phone_number_id' => self::ALERTS_PHONE_ID],
                'statuses' => [['id' => 'wamid.ALERT1', 'status' => $status, 'timestamp' => (string) now()->timestamp]],
            ],
        ]);
    }
}
