<?php

namespace Tests\Feature;

use App\Enums\AutomationRuleStatus;
use App\Enums\BroadcastStatus;
use App\Enums\ChannelType;
use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Enums\UserRole;
use App\Models\AutomationRule;
use App\Models\BroadcastCampaign;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TagManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_with_attach_can_create_but_not_update_or_destroy(): void
    {
        $tenant = $this->createTenantWithRoles();
        $seller = $this->createUser($tenant, ['tags.view', 'tags.attach']);
        $tag = $this->createTag($seller, 'VIP');
        Sanctum::actingAs($seller);

        $this->postJson('/api/tags', ['name' => 'Nuevo lead'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'nuevo-lead');

        $this->putJson("/api/tags/{$tag->id}", ['color' => '#ef4444'])->assertForbidden();
        $this->deleteJson("/api/tags/{$tag->id}")->assertForbidden();
        $this->getJson("/api/tags/{$tag->id}/usage")->assertForbidden();

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'color' => '#64748b']);
    }

    public function test_user_without_attach_cannot_create(): void
    {
        $tenant = $this->createTenantWithRoles();
        Sanctum::actingAs($this->createUser($tenant, ['tags.view']));

        $this->postJson('/api/tags', ['name' => 'Nuevo lead'])->assertForbidden();
    }

    public function test_manager_updates_color(): void
    {
        $tenant = $this->createTenantWithRoles();
        $manager = $this->createUser($tenant, ['tags.view', 'tags.manage']);
        $tag = $this->createTag($manager, 'VIP');
        Sanctum::actingAs($manager);

        $this->putJson("/api/tags/{$tag->id}", ['color' => '#ef4444'])
            ->assertOk()
            ->assertJsonPath('data.color', '#ef4444');

        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'color' => '#ef4444']);
    }

    public function test_destroy_detaches_from_contacts_and_conversations(): void
    {
        $tenant = $this->createTenantWithRoles();
        $manager = $this->createUser($tenant, ['tags.view', 'tags.manage']);
        $tag = $this->createTag($manager, 'VIP');
        [$contact, $conversation] = $this->createTaggedRecords($manager, $tag);
        Sanctum::actingAs($manager);

        $this->deleteJson("/api/tags/{$tag->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Etiqueta eliminada');

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseMissing('taggables', ['tag_id' => $tag->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
    }

    public function test_destroy_is_blocked_by_active_automation_with_has_tag_condition(): void
    {
        $tenant = $this->createTenantWithRoles();
        $manager = $this->createUser($tenant, ['tags.view', 'tags.manage']);
        $tag = $this->createTag($manager, 'VIP');
        Sanctum::actingAs($manager);

        $this->createRule($manager, 'Pausada', AutomationRuleStatus::Paused, $this->hasTagTree($tag->id));

        $this->deleteJson("/api/tags/{$tag->id}")->assertOk();

        $tag = $this->createTag($manager, 'Hot');
        // Grupo anidado y el slug como valor: así lo guarda el editor de reglas.
        $rule = $this->createRule($manager, 'Seguimiento VIP', AutomationRuleStatus::Active, [
            'operator' => 'OR',
            'conditions' => [
                ['field' => 'contact.name', 'operator' => 'equals', 'value' => 'Ana'],
                ['operator' => 'AND', 'conditions' => [
                    ['field' => 'contact.tags', 'operator' => 'has_tag', 'value' => $tag->slug],
                ]],
            ],
        ]);
        $this->createRule($manager, 'Otra etiqueta', AutomationRuleStatus::Active, $this->hasTagTree($tag->id + 100));

        $this->deleteJson("/api/tags/{$tag->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'La etiqueta está en uso en automatizaciones o difusiones programadas.')
            ->assertJsonPath('blocking.automations', [['id' => $rule->id, 'name' => 'Seguimiento VIP']])
            ->assertJsonPath('blocking.broadcasts', []);

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_destroy_is_blocked_by_scheduled_broadcast_filtering_by_tag(): void
    {
        $tenant = $this->createTenantWithRoles();
        $manager = $this->createUser($tenant, ['tags.view', 'tags.manage']);
        $tag = $this->createTag($manager, 'VIP');
        Sanctum::actingAs($manager);

        $this->createCampaign($manager, 'Terminada', BroadcastStatus::Completed, ['tag_ids' => [$tag->id]]);

        $this->deleteJson("/api/tags/{$tag->id}")->assertOk();

        $tag = $this->createTag($manager, 'Hot');
        $campaign = $this->createCampaign($manager, 'Promo', BroadcastStatus::Scheduled, ['excluded_tag_ids' => [$tag->id]]);
        $this->createCampaign($manager, 'Cancelada', BroadcastStatus::Cancelled, ['tag_ids' => [$tag->id]]);

        $this->deleteJson("/api/tags/{$tag->id}")
            ->assertStatus(409)
            ->assertJsonPath('blocking.automations', [])
            ->assertJsonPath('blocking.broadcasts', [['id' => $campaign->id, 'name' => 'Promo', 'status' => 'scheduled']]);

        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_index_with_counts_and_usage(): void
    {
        $tenant = $this->createTenantWithRoles();
        $manager = $this->createUser($tenant, ['tags.view', 'tags.manage']);
        $tag = $this->createTag($manager, 'VIP');
        $this->createTag($manager, 'Sin uso');
        $this->createTaggedRecords($manager, $tag);
        $this->createTaggedRecords($manager, $tag, '+5491122222222');
        $rule = $this->createRule($manager, 'Regla', AutomationRuleStatus::Active, $this->hasTagTree((string) $tag->id));
        $campaign = $this->createCampaign($manager, 'Promo', BroadcastStatus::Processing, ['tag_ids' => [(string) $tag->id]]);
        Sanctum::actingAs($manager);

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonMissingPath('data.0.contacts_count');

        $this->getJson('/api/tags?with_counts=1')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Sin uso')
            ->assertJsonPath('data.0.contacts_count', 0)
            ->assertJsonPath('data.1.name', 'VIP')
            ->assertJsonPath('data.1.contacts_count', 2)
            ->assertJsonPath('data.1.conversations_count', 2)
            ->assertJsonPath('data.1.opportunities_count', 0);

        $this->getJson("/api/tags/{$tag->id}/usage")
            ->assertOk()
            ->assertExactJson(['data' => [
                'contacts_count' => 2,
                'conversations_count' => 2,
                'opportunities_count' => 0,
                'blocking' => [
                    'automations' => [['id' => $rule->id, 'name' => 'Regla']],
                    'broadcasts' => [['id' => $campaign->id, 'name' => 'Promo', 'status' => 'processing']],
                ],
            ]]);
    }

    public function test_cross_tenant_tag_is_not_accessible(): void
    {
        $otherTenant = $this->createTenantWithRoles('Otro');
        $foreignTag = $this->createTag($this->createUser($otherTenant, ['tags.manage']), 'Ajena');

        $tenant = $this->createTenantWithRoles();
        Sanctum::actingAs($this->createUser($tenant, ['tags.view', 'tags.manage']));

        $this->putJson("/api/tags/{$foreignTag->id}", ['color' => '#ef4444'])->assertNotFound();
        $this->deleteJson("/api/tags/{$foreignTag->id}")->assertNotFound();
        $this->getJson("/api/tags/{$foreignTag->id}/usage")->assertNotFound();

        $this->assertDatabaseHas('tags', ['id' => $foreignTag->id, 'color' => '#64748b']);
    }

    /** @param list<string> $permissions */
    private function createUser(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::EMPLOYEE,
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function createTag(User $user, string $name): Tag
    {
        return Tag::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            'name' => $name,
            'slug' => str($name)->slug()->toString(),
            'color' => '#64748b',
        ]);
    }

    /** @return array{0: Contact, 1: Conversation} */
    private function createTaggedRecords(User $user, Tag $tag, string $phone = '+5491111111111'): array
    {
        $channel = Channel::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $user->tenant_id, 'name' => 'WhatsApp'],
            ['user_id' => $user->id, 'type' => ChannelType::WHATSAPP, 'status' => 'active'],
        );
        $contact = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Jane Doe',
            'phone' => $phone,
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $pivot = ['tenant_id' => $user->tenant_id, 'assigned_by' => $user->id];
        $contact->tags()->attach($tag->id, $pivot);
        $conversation->tags()->attach($tag->id, $pivot);

        return [$contact, $conversation];
    }

    private function hasTagTree(int|string $value): array
    {
        return [
            'operator' => 'AND',
            'conditions' => [
                ['field' => 'contact.tags', 'operator' => 'has_tag', 'value' => $value],
            ],
        ];
    }

    private function createRule(User $user, string $name, AutomationRuleStatus $status, array $conditions): AutomationRule
    {
        return AutomationRule::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            'name' => $name,
            'status' => $status,
            'trigger_type' => 'date.reached',
            'conditions' => $conditions,
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
    }

    private function createCampaign(User $user, string $name, BroadcastStatus $status, array $filters): BroadcastCampaign
    {
        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0101',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
        $channel = Channel::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp '.uniqid(),
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);
        $template = WhatsAppTemplate::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'whatsapp_config_id' => $config->id,
            'external_id' => 'template-'.uniqid(),
            'name' => 'promo_agosto',
            'language' => 'es_AR',
            'category' => TemplateCategory::Marketing,
            'status' => TemplateStatus::Approved,
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']],
            'synced_at' => now(),
        ]);

        return BroadcastCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id,
            'channel_id' => $channel->id,
            'whatsapp_template_id' => $template->id,
            'created_by' => $user->id,
            'name' => $name,
            'status' => $status,
            'audience_filters' => $filters,
            'scheduled_at' => now()->addDay(),
        ]);
    }
}
