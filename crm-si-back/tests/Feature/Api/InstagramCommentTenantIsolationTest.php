<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\InstagramComment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InstagramCommentTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_only_returns_comments_from_authenticated_users_tenant(): void
    {
        [$tenantA, $ownerA, $channelA] = $this->createTenantWithOwnerAndChannel('Tenant A');
        [$tenantB, , $channelB] = $this->createTenantWithOwnerAndChannel('Tenant B');

        $commentA = $this->createComment($tenantA, $channelA, 'comment-a');
        $commentB = $this->createComment($tenantB, $channelB, 'comment-b');

        Sanctum::actingAs($ownerA);

        $response = $this->getJson('/api/instagram-comments')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($commentA->id));
        $this->assertFalse($ids->contains($commentB->id));
    }

    public function test_comment_from_another_tenant_is_not_route_bound(): void
    {
        [, $ownerA] = $this->createTenantWithOwnerAndChannel('Tenant A');
        [$tenantB, , $channelB] = $this->createTenantWithOwnerAndChannel('Tenant B');
        $commentB = $this->createComment($tenantB, $channelB, 'comment-b');

        Sanctum::actingAs($ownerA);

        $this->getJson("/api/instagram-comments/{$commentB->id}")->assertNotFound();
    }

    public function test_comment_cannot_be_assigned_to_user_from_another_tenant(): void
    {
        [$tenantA, $ownerA, $channelA] = $this->createTenantWithOwnerAndChannel('Tenant A');
        [, $ownerB] = $this->createTenantWithOwnerAndChannel('Tenant B');
        $commentA = $this->createComment($tenantA, $channelA, 'comment-a');

        Sanctum::actingAs($ownerA);

        $this->patchJson("/api/instagram-comments/{$commentA->id}", [
            'assigned_to' => $ownerB->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('assigned_to');

        $this->assertNull($commentA->fresh()->assigned_to);
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Channel}
     */
    private function createTenantWithOwnerAndChannel(string $name): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $registrar->forgetCachedPermissions();
        $tenant = Tenant::create(['name' => $name]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $owner->assignRole('Owner');

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'type' => ChannelType::INSTAGRAM,
            'name' => '@'.strtolower(str_replace(' ', '-', $name)),
            'status' => 'active',
        ]);

        return [$tenant, $owner, $channel];
    }

    private function createComment(Tenant $tenant, Channel $channel, string $externalId): InstagramComment
    {
        return InstagramComment::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'external_id' => $externalId,
            'author_external_id' => 'author-'.$externalId,
            'text' => $externalId,
            'commented_at' => now(),
        ]);
    }
}
