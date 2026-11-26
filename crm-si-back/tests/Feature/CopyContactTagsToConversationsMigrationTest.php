<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CopyContactTagsToConversationsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_copies_contact_tags_to_every_conversation_once(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $channel = Channel::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'type' => ChannelType::WHATSAPP, 'name' => 'WhatsApp', 'status' => 'active']);
        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana', 'source' => 'whatsapp']);
        $first = Conversation::create(['tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $second = Conversation::create(['tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $untagged = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Beto', 'source' => 'whatsapp']);
        $other = Conversation::create(['tenant_id' => $tenant->id, 'channel_id' => $channel->id, 'contact_id' => $untagged->id, 'status' => 'open']);

        $vip = $this->tag($tenant, $user, 'VIP');
        $hot = $this->tag($tenant, $user, 'Hot');
        $pivot = ['tenant_id' => $tenant->id, 'assigned_by' => $user->id];
        $contact->tags()->attach([$vip->id => $pivot, $hot->id => $pivot]);
        $first->tags()->attach($vip->id, $pivot);

        $migration = require database_path('migrations/2026_11_26_000000_copy_contact_tags_to_conversations.php');
        $migration->up();
        $migration->up();

        $this->assertEqualsCanonicalizing([$vip->id, $hot->id], $first->tags()->pluck('tags.id')->all());
        $this->assertEqualsCanonicalizing([$vip->id, $hot->id], $second->tags()->pluck('tags.id')->all());
        $this->assertSame([], $other->tags()->pluck('tags.id')->all());
        $this->assertSame(6, DB::table('taggables')->count());
    }

    private function tag(Tenant $tenant, User $user, string $name): Tag
    {
        return Tag::create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'name' => $name,
            'slug' => strtolower($name),
            'color' => '#64748b',
        ]);
    }
}
