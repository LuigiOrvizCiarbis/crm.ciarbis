<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationAwaitingHumanFilterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenantWithRoles();
        $this->admin = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::ADMIN]);
        $this->admin->assignRole('Admin');
        $this->channel = Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->admin->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);
    }

    public function test_lists_only_conversations_with_an_active_handoff(): void
    {
        $pending = $this->conversation('Pendiente', 'pending');
        $taken = $this->conversation('Tomada', 'acknowledged');
        $this->conversation('Resuelta', 'resolved');
        $this->conversation('Cancelada', 'cancelled');
        $this->conversation('Sin derivar', null);

        Sanctum::actingAs($this->admin);

        $ids = collect($this->getJson('/api/conversations?awaiting_human=1&cursor_mode=1')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$pending->id, $taken->id], $ids);

        $this->assertCount(5, $this->getJson('/api/conversations?cursor_mode=1')->assertOk()->json('data'));
    }

    private function conversation(string $name, ?string $handoffStatus): Conversation
    {
        $contact = Contact::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'source' => 'whatsapp']);
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        if ($handoffStatus !== null) {
            DB::table('human_handoffs')->insert([
                'tenant_id' => $this->tenant->id,
                'conversation_id' => $conversation->id,
                'channel_id' => $this->channel->id,
                'status' => $handoffStatus,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $conversation;
    }
}
