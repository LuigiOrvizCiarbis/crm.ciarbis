<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_and_attach_tags_to_contacts_and_conversations(): void
    {
        [$user, $contact, $conversation] = $this->createCrmRecords();
        Sanctum::actingAs($user);

        $tagId = $this->postJson('/api/tags', [
            'name' => 'Hot Lead',
            'color' => '#ef4444',
            'type' => 'commercial',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Hot Lead')
            ->assertJsonPath('data.slug', 'hot-lead')
            ->json('data.id');

        $this->postJson("/api/contacts/{$contact->id}/tags", [
            'tag_ids' => [$tagId],
        ])
            ->assertOk()
            ->assertJsonPath('data.tags.0.id', $tagId);

        $this->postJson("/api/conversations/{$conversation->id}/tags", [
            'tag_ids' => [$tagId],
        ])
            ->assertOk()
            ->assertJsonPath('data.tags.0.id', $tagId);

        $this->getJson('/api/contacts?tags=hot-lead')
            ->assertOk()
            ->assertJsonPath('data.0.id', $contact->id)
            ->assertJsonPath('data.0.tags.0.slug', 'hot-lead');

        $this->getJson('/api/conversations?tags=hot-lead')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversation->id)
            ->assertJsonPath('data.0.tags.0.slug', 'hot-lead');
    }

    public function test_tag_names_are_unique_per_tenant(): void
    {
        [$user] = $this->createCrmRecords();
        Sanctum::actingAs($user);

        Tag::create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            'name' => 'VIP',
            'slug' => 'vip',
            'color' => '#0f766e',
        ]);

        $this->postJson('/api/tags', [
            'name' => 'VIP',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    private function createCrmRecords(): array
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        // Listar contactos/conversaciones se autoriza por permisos de Spatie.
        $user->assignRole('Owner');

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return [$user, $contact, $conversation];
    }
}
