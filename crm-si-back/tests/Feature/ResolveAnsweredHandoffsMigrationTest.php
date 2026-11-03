<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResolveAnsweredHandoffsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $contact = Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Ana', 'source' => 'whatsapp']);
        $channel = Channel::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'type' => ChannelType::WHATSAPP, 'name' => 'WhatsApp', 'status' => 'active']);
        $this->conversation = Conversation::create(['tenant_id' => $this->tenant->id, 'channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => 'open']);
    }

    public function test_closes_active_handoffs_at_the_first_later_human_reply(): void
    {
        $answered = $this->handoff('pending', '2026-09-15 10:00:00');
        $acknowledged = $this->handoff('acknowledged', '2026-09-16 10:00:00');
        $unanswered = $this->handoff('pending', '2026-09-20 10:00:00');
        $cancelled = $this->handoff('cancelled', '2026-09-14 10:00:00');

        $this->message('user', '2026-09-14 09:00:00');           // antes de todas: no cuenta
        $this->message('user', '2026-09-15 10:20:00');           // primera respuesta a $answered
        $this->message('user', '2026-09-15 11:00:00', deleted: true);
        $this->message('contact', '2026-09-16 10:05:00', 'inbound');
        $this->message('user', '2026-09-16 10:30:00');           // primera respuesta a $acknowledged

        (require database_path('migrations/2026_11_03_000002_resolve_answered_human_handoffs.php'))->up();

        $this->assertHandoff($answered, 'resolved', '2026-09-15 10:20:00');
        $this->assertHandoff($acknowledged, 'resolved', '2026-09-16 10:30:00');
        $this->assertHandoff($unanswered, 'pending', null);
        $this->assertHandoff($cancelled, 'cancelled', null);
    }

    private function handoff(string $status, string $at): int
    {
        return DB::table('human_handoffs')->insertGetId([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'channel_id' => $this->conversation->channel_id,
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function message(string $sender, string $at, string $direction = 'outbound', bool $deleted = false): void
    {
        DB::table('messages')->insert([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'sender_type' => $sender,
            'direction' => $direction,
            'content' => 'hola',
            'created_at' => $at,
            'updated_at' => $at,
            'deleted_at' => $deleted ? $at : null,
        ]);
    }

    private function assertHandoff(int $id, string $status, ?string $resolvedAt): void
    {
        $row = DB::table('human_handoffs')->find($id);
        $this->assertSame($status, $row->status);
        $this->assertSame($resolvedAt, $row->resolved_at);
    }
}
