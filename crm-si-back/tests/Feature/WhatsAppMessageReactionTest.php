<?php

namespace Tests\Feature;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\BroadcastStatus;
use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Events\MessageReactionUpdated;
use App\Models\BroadcastCampaign;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageInteraction;
use App\Models\MessageReaction;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Services\WhatsAppMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppMessageReactionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Tenant, 1: Channel}
     */
    private function createWhatsAppChannelContext(): array
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);

        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'display_phone_number' => '+54 9 223 511-2208',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        $channel->update(['whatsapp_config_id' => $config->id]);

        return [$tenant, $channel->fresh()];
    }

    private function createInboundMessage(Conversation $conversation, Contact $contact, string $externalId): Message
    {
        return Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => $externalId,
        ]);
    }

    private function reactionWebhookPayload(string $targetExternalId, string $from, ?string $emoji, string $eventId = 'wamid.reaction-event', ?int $timestamp = null): array
    {
        return [
            'value' => [
                'metadata' => ['phone_number_id' => '123456789'],
                'contacts' => [['profile' => ['name' => 'Jane Doe']]],
                'messages' => [[
                    'from' => $from,
                    'id' => $eventId,
                    'timestamp' => (string) ($timestamp ?? now()->timestamp),
                    'type' => 'reaction',
                    'reaction' => array_filter([
                        'message_id' => $targetExternalId,
                        'emoji' => $emoji,
                    ], static fn ($v) => $v !== null),
                ]],
            ],
        ];
    }

    /**
     * Fix central: hoy recordReactionInteraction() sólo guarda si el target
     * pertenece a una campaña con resultados habilitados. Un chat normal
     * debe seguir registrando la reacción.
     */
    public function test_incoming_reaction_is_stored_for_any_conversation_not_only_broadcast_campaigns(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelContext();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $target = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $channel->user_id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => 'wamid.target-1',
        ]);

        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.target-1', '5492235112208', '👍')
        );

        $reaction = MessageReaction::where('message_id', $target->id)->first();
        $this->assertNotNull($reaction);
        $this->assertSame('👍', $reaction->emoji);
        $this->assertSame(SenderType::CONTACT, $reaction->reactor_type);
        $this->assertSame($contact->id, $reaction->reactor_id);
        $this->assertSame($tenant->id, $reaction->tenant_id);
    }

    public function test_incoming_reaction_with_empty_emoji_removes_existing_reaction(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelContext();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $target = $this->createInboundMessage($conversation, $contact, 'wamid.target-2');
        $target->update(['sender_type' => SenderType::USER, 'direction' => MessageDirection::OUTBOUND]);

        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.target-2', '5492235112208', '👍', 'wamid.reaction-add')
        );
        $this->assertSame(1, MessageReaction::where('message_id', $target->id)->count());

        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.target-2', '5492235112208', null, 'wamid.reaction-remove')
        );

        $this->assertSame(0, MessageReaction::where('message_id', $target->id)->count());
    }

    public function test_repeated_incoming_reaction_webhook_is_deduplicated(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelContext();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $target = $this->createInboundMessage($conversation, $contact, 'wamid.target-3');
        $target->update(['sender_type' => SenderType::USER, 'direction' => MessageDirection::OUTBOUND]);

        $payload = $this->reactionWebhookPayload('wamid.target-3', '5492235112208', '👍', 'wamid.reaction-dup');

        app(WhatsAppMessageService::class)->processIncomingMessage($payload);
        app(WhatsAppMessageService::class)->processIncomingMessage($payload);

        $this->assertSame(1, MessageReaction::where('message_id', $target->id)->count());
    }

    public function test_incoming_reaction_to_unknown_message_is_ignored_without_error(): void
    {
        [, $channel] = $this->createWhatsAppChannelContext();

        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.does-not-exist', '5492235112208', '👍')
        );

        $this->assertSame(0, MessageReaction::count());
    }

    /**
     * Meta entrega con garantía at-least-once: un webhook reentregado o fuera
     * de orden no debe pisar un estado más nuevo.
     */
    public function test_out_of_order_incoming_reaction_webhook_is_ignored(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelContext();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $target = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $channel->user_id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => 'wamid.out-of-order',
        ]);

        $now = now()->timestamp;

        // Llega primero ❤️ (más reciente), después 👍 reentregado/demorado
        // (más viejo): el 👍 no debe pisar al ❤️.
        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.out-of-order', '5492235112208', '❤️', 'wamid.reaction-newer', $now + 10)
        );
        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.out-of-order', '5492235112208', '👍', 'wamid.reaction-older', $now)
        );

        $reaction = MessageReaction::where('message_id', $target->id)->first();
        $this->assertNotNull($reaction);
        $this->assertSame('❤️', $reaction->emoji);
    }

    public function test_incoming_reaction_from_other_tenant_is_rejected(): void
    {
        [$tenantA, $channelA] = $this->createWhatsAppChannelContext();
        [$tenantB, $channelB] = $this->createWhatsAppChannelContext();

        $contactB = Contact::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Contact B',
            'phone' => '5492235112209',
            'source' => 'whatsapp',
        ]);
        $conversationB = Conversation::create([
            'tenant_id' => $tenantB->id,
            'channel_id' => $channelB->id,
            'contact_id' => $contactB->id,
            'status' => 'open',
        ]);

        // wamid con la misma forma en ambos tenants: external_id es unique
        // global, así que este caso simula la resolución cross-tenant.
        $targetB = Message::create([
            'tenant_id' => $tenantB->id,
            'conversation_id' => $conversationB->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $channelB->user_id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => 'wamid.cross-tenant',
        ]);

        // El webhook llega resuelto contra el canal del tenant A, pero el
        // wamid resuelve a un mensaje del tenant B.
        app(WhatsAppMessageService::class)->processIncomingMessage([
            'value' => [
                'metadata' => ['phone_number_id' => $channelA->whatsappConfig->phone_number_id],
                'contacts' => [['profile' => ['name' => 'Attacker']]],
                'messages' => [[
                    'from' => '5492235112299',
                    'id' => 'wamid.cross-tenant-event',
                    'timestamp' => (string) now()->timestamp,
                    'type' => 'reaction',
                    'reaction' => ['message_id' => 'wamid.cross-tenant', 'emoji' => '👍'],
                ]],
            ],
        ]);

        $this->assertSame(0, MessageReaction::where('message_id', $targetB->id)->count());
    }

    public function test_incoming_group_reactions_from_multiple_participants_aggregate_with_count(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelContext();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $target = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $channel->user_id,
            'content' => 'Hola grupo',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => 'wamid.group-target',
        ]);

        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.group-target', '5492235112208', '👍', 'wamid.reaction-p1')
        );
        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.group-target', '5492235112230', '👍', 'wamid.reaction-p2')
        );

        $target->load('reactions');
        $summary = MessageReaction::summaryFor($target);

        $this->assertCount(1, $summary);
        $this->assertSame(2, $summary[0]['count']);
    }

    /**
     * No-regresión: el flujo de analítica de difusiones (message_interactions
     * + BroadcastResultsUpdated) debe seguir intacto para campañas con
     * resultados habilitados.
     */
    public function test_incoming_reaction_still_records_broadcast_interaction_when_campaign_results_enabled(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelContext();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $template = \App\Models\WhatsAppTemplate::create([
            'tenant_id' => $tenant->id,
            'whatsapp_config_id' => $channel->whatsapp_config_id,
            'external_id' => 'template-'.uniqid(),
            'name' => 'promo',
            'language' => 'es_AR',
            'category' => \App\Enums\TemplateCategory::Marketing,
            'status' => \App\Enums\TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola']],
            'synced_at' => now(),
        ]);

        $campaign = BroadcastCampaign::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => $channel->user_id,
            'name' => 'Campaña de prueba',
            'status' => BroadcastStatus::Completed,
            'audience_count' => 1,
            'scheduled_at' => now(),
            'results_tracking_version' => 1,
        ]);

        $target = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $channel->user_id,
            'content' => 'Hola desde difusión',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'external_id' => 'wamid.campaign-target',
        ]);

        BroadcastRecipient::create([
            'broadcast_campaign_id' => $campaign->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'message_id' => $target->id,
            'status' => BroadcastRecipientStatus::Sent,
            'sent_at' => now(),
        ]);

        app(WhatsAppMessageService::class)->processIncomingMessage(
            $this->reactionWebhookPayload('wamid.campaign-target', '5492235112208', '👍')
        );

        $this->assertSame(1, MessageReaction::where('message_id', $target->id)->count());

        $interaction = MessageInteraction::where('target_message_id', $target->id)->first();
        $this->assertNotNull($interaction);
        $this->assertSame('reaction', $interaction->type);
        $this->assertSame('👍', $interaction->value);
    }

/**
     * A diferencia de createWhatsAppChannelContext() (usado por los tests de
     * webhook, que no pasan por policies), este setup usa createTenantWithRoles()
     * para que el registrar de Spatie quede apuntando al tenant y assignRole
     * funcione: las policies de Message/Conversation autorizan por permisos,
     * no por el enum `role` del usuario.
     *
     * @return array{0: Tenant, 1: Channel}
     */
    private function createWhatsAppChannelWithRoles(string $tenantName = 'Acme'): array
    {
        $tenant = $this->createTenantWithRoles($tenantName);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 511-2208',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);
        $channel->update(['whatsapp_config_id' => $config->id]);

        return [$tenant, $channel->fresh()];
    }

    /**
     * @return array{0: User, 1: Message}
     */
    private function createOutboundConversationWithMessage(): array
    {
        [$tenant, $channel] = $this->createWhatsAppChannelWithRoles();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'wamid.outbound-target',
        ]);

        return [$user, $message];
    }

    public function test_user_can_react_to_message_from_crm(): void
    {
        [$user, $message] = $this->createOutboundConversationWithMessage();

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.reaction-sent']]], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍']);

        $response->assertOk();

        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'reactor_type' => 'user',
            'reactor_id' => $user->id,
            'emoji' => '👍',
            'external_id' => 'wamid.reaction-sent',
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/messages')
                && $request['type'] === 'reaction'
                && $request['reaction']['message_id'] === 'wamid.outbound-target'
                && $request['reaction']['emoji'] === '👍';
        });
    }

    public function test_reacting_again_with_different_emoji_replaces_the_previous_one(): void
    {
        [$user, $message] = $this->createOutboundConversationWithMessage();

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.reaction-1']]], 200)
                ->push(['messages' => [['id' => 'wamid.reaction-2']]], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍'])->assertOk();
        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '❤️'])->assertOk();

        $this->assertSame(1, MessageReaction::where('message_id', $message->id)->where('reactor_type', 'user')->count());
        $this->assertDatabaseHas('message_reactions', [
            'message_id' => $message->id,
            'reactor_type' => 'user',
            'reactor_id' => $user->id,
            'emoji' => '❤️',
        ]);
    }

    /**
     * Regla de Q1: el contacto sólo ve una reacción del negocio (un único
     * número de WhatsApp). Si dos usuarios del CRM reaccionan al mismo
     * mensaje, el segundo reemplaza al primero.
     */
    public function test_two_crm_users_reacting_to_same_message_results_in_single_business_reaction(): void
    {
        [$juan, $message] = $this->createOutboundConversationWithMessage();
        $tenant = Tenant::find($juan->tenant_id);
        $ana = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $ana->assignRole('Owner');

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.juan-reaction']]], 200)
                ->push(['messages' => [['id' => 'wamid.ana-reaction']]], 200),
        ]);

        Sanctum::actingAs($juan);
        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍'])->assertOk();

        Sanctum::actingAs($ana);
        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '❤️'])->assertOk();

        $reactions = MessageReaction::where('message_id', $message->id)->where('reactor_type', 'user')->get();
        $this->assertCount(1, $reactions);
        $this->assertSame('❤️', $reactions->first()->emoji);
        $this->assertSame($ana->id, $reactions->first()->reactor_id);
    }

    public function test_reacting_with_empty_emoji_removes_the_reaction(): void
    {
        [$user, $message] = $this->createOutboundConversationWithMessage();

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.reaction-1']]], 200)
                ->push(['messages' => [['id' => 'wamid.reaction-removed']]], 200),
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍'])->assertOk();
        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => ''])->assertOk();

        $this->assertDatabaseMissing('message_reactions', [
            'message_id' => $message->id,
            'reactor_type' => 'user',
            'reactor_id' => $user->id,
        ]);

        Http::assertSent(fn ($request) => ($request['reaction']['emoji'] ?? null) === '');
    }

    public function test_reacting_to_message_without_external_id_returns_422(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Sin sincronizar',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => null,
        ]);

        Http::fake();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍']);

        $response->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(0, MessageReaction::count());
    }

    public function test_meta_error_131009_returns_friendly_message(): void
    {
        [$user, $message] = $this->createOutboundConversationWithMessage();

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response([
                'error' => ['code' => 131009, 'message' => 'Parameter value is not valid'],
            ], 400),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍']);

        $response->assertStatus(422);
        $this->assertStringContainsString('30 días', $response->json('message'));
        $this->assertSame(0, MessageReaction::count());
    }

    public function test_user_without_send_message_permission_cannot_react(): void
    {
        [$tenant, $channel] = $this->createWhatsAppChannelWithRoles();

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);
        // Sin assignRole: usuario sin ningún permiso Spatie.

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'wamid.no-permission',
        ]);

        Http::fake();
        Sanctum::actingAs($user);

        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍'])
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_user_cannot_react_to_message_of_another_tenant(): void
    {
        [$tenantA, $channelA] = $this->createWhatsAppChannelWithRoles('Acme A');
        // createTenantWithRoles deja el registrar apuntando al tenant creado;
        // no hace falta que tenantB tenga roles: nunca se autentica como su
        // usuario, sólo se usa su mensaje como target ajeno.
        [$tenantB, $channelB] = $this->createWhatsAppChannelContext();

        $userA = User::factory()->create(['tenant_id' => $tenantA->id, 'role' => UserRole::ADMIN]);
        $userA->assignRole('Owner');

        $contactB = Contact::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Contact B',
            'phone' => '5492235112209',
            'source' => 'whatsapp',
        ]);
        $conversationB = Conversation::create([
            'tenant_id' => $tenantB->id,
            'channel_id' => $channelB->id,
            'contact_id' => $contactB->id,
            'status' => 'open',
        ]);
        $messageB = Message::create([
            'tenant_id' => $tenantB->id,
            'conversation_id' => $conversationB->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contactB->id,
            'content' => 'Hola',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'external_id' => 'wamid.tenant-b-message',
        ]);

        Http::fake();
        Sanctum::actingAs($userA);

        $this->postJson("/api/messages/{$messageB->id}/reaction", ['emoji' => '👍'])
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_reaction_does_not_create_a_message_or_bump_conversation_preview(): void
    {
        [$user, $message] = $this->createOutboundConversationWithMessage();
        $conversation = $message->conversation;
        $conversation->update(['last_message_content' => 'Hola', 'last_message_at' => now()->subHour()]);
        $previousMessageCount = Message::count();

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.reaction-x']]], 200),
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍'])->assertOk();

        $this->assertSame($previousMessageCount, Message::count());
        $conversation->refresh();
        $this->assertSame('Hola', $conversation->last_message_content);
    }

    public function test_reaction_broadcasts_updated_summary(): void
    {
        Event::fake([MessageReactionUpdated::class]);

        [$user, $message] = $this->createOutboundConversationWithMessage();

        Http::fake([
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.reaction-broadcast']]], 200),
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/messages/{$message->id}/reaction", ['emoji' => '👍'])->assertOk();

        Event::assertDispatched(MessageReactionUpdated::class, function (MessageReactionUpdated $event) use ($message) {
            return $event->messageId === $message->id
                && $event->summary !== []
                && $event->summary[0]['emoji'] === '👍';
        });
    }
}
