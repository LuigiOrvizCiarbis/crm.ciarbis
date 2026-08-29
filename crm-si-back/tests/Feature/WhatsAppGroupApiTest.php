<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\User;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppGroupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_pending_group_and_correlates_by_request_id(): void
    {
        [$user, $channel] = $this->context();
        Http::fake([
            'https://graph.facebook.com/*/*' => Http::response([
                'is_official_business_account' => true,
                'is_on_biz_app' => false,
                'platform_type' => 'CLOUD_API',
                'messaging_product' => 'whatsapp',
            ], 200),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/whatsapp-groups', [
            'channel_id' => $channel->id,
            'subject' => 'Venta Juan Pérez',
            'description' => 'Coordinación de compra',
            'join_approval_mode' => 'auto_approve',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subject', 'Venta Juan Pérez')
            ->assertJsonPath('data.group_id', null);

        $this->assertDatabaseHas('whatsapp_groups', [
            'channel_id' => $channel->id,
            'subject' => 'Venta Juan Pérez',
            'status' => 'pending',
        ]);
    }

    public function test_store_is_blocked_when_channel_is_not_official_business_account(): void
    {
        [$user, $channel] = $this->context();
        Http::fake([
            'https://graph.facebook.com/*/*' => Http::response([
                'is_official_business_account' => false,
                'is_on_biz_app' => false,
                'platform_type' => 'CLOUD_API',
            ], 200),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/whatsapp-groups', [
            'channel_id' => $channel->id,
            'subject' => 'Venta sin OBA',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('whatsapp_groups', ['subject' => 'Venta sin OBA']);
    }

    public function test_store_is_blocked_when_channel_is_on_coexistence(): void
    {
        [$user, $channel] = $this->context();
        Http::fake([
            'https://graph.facebook.com/*/*' => Http::response([
                'is_official_business_account' => true,
                'is_on_biz_app' => true,
                'platform_type' => 'SMB_APP',
            ], 200),
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/whatsapp-groups', [
            'channel_id' => $channel->id,
            'subject' => 'Venta coexistencia',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('whatsapp_groups', ['subject' => 'Venta coexistencia']);
    }

    public function test_store_rejects_subject_longer_than_128_chars(): void
    {
        [$user, $channel] = $this->context();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/whatsapp-groups', [
            'channel_id' => $channel->id,
            'subject' => str_repeat('a', 129),
        ]);

        $response->assertStatus(422);
    }

    public function test_remove_participants_rejects_more_than_eight(): void
    {
        [$user, $channel] = $this->context();
        $group = $this->createActiveGroup($channel);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/whatsapp-groups/{$group->id}/participants/remove", [
            'participants' => array_map(fn ($i) => "+549111111111{$i}", range(0, 8)),
        ]);

        $response->assertStatus(422);
    }

    public function test_member_without_permission_cannot_delete_group(): void
    {
        [$owner, $channel] = $this->context();
        $tenant = $owner->tenant;
        $member = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);
        $member->assignRole('Member');
        $group = $this->createActiveGroup($channel);

        Sanctum::actingAs($member);

        $response = $this->deleteJson("/api/whatsapp-groups/{$group->id}");

        $response->assertStatus(403);
    }

    public function test_member_can_create_and_view_groups(): void
    {
        [$owner, $channel] = $this->context();
        $tenant = $owner->tenant;
        $member = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);
        $member->assignRole('Member');
        // El vendedor sólo puede crear grupos en canales a los que tiene
        // acceso: accessibleChannelIds() mira ownedChannels() + la pivot
        // channel_user, no es automático por pertenecer al tenant.
        $member->channels()->attach($channel->id);
        Http::fake(['https://graph.facebook.com/*/*' => Http::response([
            'is_official_business_account' => true,
            'is_on_biz_app' => false,
            'platform_type' => 'CLOUD_API',
            'messaging_product' => 'whatsapp',
        ], 200)]);

        Sanctum::actingAs($member);

        $response = $this->postJson('/api/whatsapp-groups', [
            'channel_id' => $channel->id,
            'subject' => 'Venta del vendedor',
        ]);

        $response->assertCreated();
    }

    private function createActiveGroup(Channel $channel): WhatsAppGroup
    {
        return WhatsAppGroup::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'group_id' => 'group-test-123@g.us',
            'subject' => 'Grupo activo',
            'status' => 'active',
        ]);
    }

    private function context(): array
    {
        $tenant = $this->createTenantWithRoles('Acme '.uniqid());
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        $config = WhatsAppConfig::create([
            'phone_number_id' => '123456789',
            'display_phone_number' => '+54 9 11 0000-0000',
            'waba_id' => 'waba-test',
            'bussines_token' => Crypt::encryptString('test-token'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
            'whatsapp_config_id' => $config->id,
        ]);

        return [$user, $channel];
    }
}
