<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationChannelFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_filter_includes_conversations_assigned_to_channel_owner(): void
    {
        $tenant = $this->createTenantWithRoles();

        $admin = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        // Listar conversaciones se autoriza por permisos de Spatie; el enum
        // `role` por sí solo no habilita nada.
        $admin->assignRole('Admin');

        $seller = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::EMPLOYEE,
        ]);
        $seller->assignRole('Member');

        $selectedChannel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $seller->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Seller channel',
            'status' => 'active',
        ]);

        $otherChannel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Other channel',
            'status' => 'active',
        ]);

        $directContact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Direct Contact',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        $assignedContact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Assigned Contact',
            'phone' => '+5492222222222',
            'source' => 'whatsapp',
        ]);

        $directConversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $selectedChannel->id,
            'contact_id' => $directContact->id,
            'status' => 'open',
            'last_message_at' => now()->subMinute(),
        ]);

        $assignedConversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $otherChannel->id,
            'contact_id' => $assignedContact->id,
            'assigned_to' => $seller->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/conversations?channel_id={$selectedChannel->id}");

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($directConversation->id));
        $this->assertTrue($ids->contains($assignedConversation->id));
    }

    public function test_unread_count_summary_counts_visible_unread_messages_without_returning_conversations(): void
    {
        $tenant = $this->createTenantWithRoles();

        $seller = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::EMPLOYEE,
        ]);
        $seller->assignRole('Member');

        $otherSeller = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::EMPLOYEE,
        ]);
        $otherSeller->assignRole('Member');

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $seller->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Seller channel',
            'status' => 'active',
        ]);

        $otherChannel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $otherSeller->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Other channel',
            'status' => 'active',
        ]);

        $visibleContact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Visible Contact',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        $hiddenContact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Hidden Contact',
            'phone' => '+5492222222222',
            'source' => 'whatsapp',
        ]);

        $visibleConversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $visibleContact->id,
            'status' => 'open',
        ]);
        $visibleConversation->users()->attach($seller->id);

        $hiddenConversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $otherChannel->id,
            'contact_id' => $hiddenContact->id,
            'status' => 'open',
        ]);
        $hiddenConversation->users()->attach($otherSeller->id);

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $visibleConversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $visibleContact->id,
            'content' => 'Unread inbound',
            'direction' => MessageDirection::INBOUND,
        ]);

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $visibleConversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $visibleContact->id,
            'content' => 'Read inbound',
            'direction' => MessageDirection::INBOUND,
            'read_at' => now(),
        ]);

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $visibleConversation->id,
            'sender_type' => SenderType::USER,
            'sender_id' => $seller->id,
            'content' => 'Unread outbound',
            'direction' => MessageDirection::OUTBOUND,
        ]);

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $hiddenConversation->id,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $hiddenContact->id,
            'content' => 'Hidden unread inbound',
            'direction' => MessageDirection::INBOUND,
        ]);

        Sanctum::actingAs($seller);

        $this->getJson('/api/conversations?summary=unread_count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonMissingPath('data.0.id');
    }
}
