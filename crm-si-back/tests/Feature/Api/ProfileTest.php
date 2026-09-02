<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    // -- Datos del perfil -----------------------------------------------

    public function test_member_without_users_update_permission_can_edit_own_profile(): void
    {
        [$tenant] = $this->makeOwnerTenant();
        $member = $this->makeMember($tenant);
        Sanctum::actingAs($member);

        $this->putJson('/api/profile', [
            'name' => 'Nuevo Nombre',
            'phone' => '+54 9 11 1234-5678',
            'job_title' => 'Vendedor',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Nuevo Nombre')
            ->assertJsonPath('data.phone', '+54 9 11 1234-5678')
            ->assertJsonPath('data.job_title', 'Vendedor');

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'name' => 'Nuevo Nombre',
        ]);
    }

    public function test_profile_update_requires_name(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        Sanctum::actingAs($owner);

        $this->putJson('/api/profile', ['name' => ''])->assertStatus(422);
    }

    // -- Contraseña -------------------------------------------------------

    public function test_wrong_current_password_is_rejected_and_nothing_changes(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        Sanctum::actingAs($owner);

        $this->putJson('/api/profile/password', [
            'current_password' => 'not-the-password',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertStatus(401);

        $this->assertTrue(Hash::check('password', $owner->fresh()->password));
    }

    public function test_password_change_revokes_other_tokens_but_not_current(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();

        $currentToken = $owner->createToken('current-session');
        $otherToken = $owner->createToken('other-device');

        Sanctum::actingAs($owner);
        // Sanctum::actingAs mockea un token propio; lo pisamos con el real
        // para que currentAccessToken() coincida con la sesión "actual".
        $owner->withAccessToken($currentToken->accessToken);

        $response = $this->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        $this->assertTrue(Hash::check('brand-new-password-123', $owner->fresh()->password));
    }

    // -- Avatar -------------------------------------------------------------

    public function test_avatar_upload_replaces_previous_file(): void
    {
        Storage::fake('public');
        [$tenant, $owner] = $this->makeOwnerTenant();
        Sanctum::actingAs($owner);

        $first = $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar1.jpg', 300, 300),
        ])->assertOk();

        $firstPath = $owner->fresh()->avatar_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar2.jpg', 300, 300),
        ])->assertOk();

        Storage::disk('public')->assertMissing($firstPath);
        $this->assertNotSame($firstPath, $owner->fresh()->avatar_path);
    }

    public function test_avatar_upload_rejects_non_image(): void
    {
        Storage::fake('public');
        [$tenant, $owner] = $this->makeOwnerTenant();
        Sanctum::actingAs($owner);

        $this->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    // -- Sesiones -------------------------------------------------------

    public function test_cannot_revoke_another_users_token(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $otherUser = $this->makeMember($tenant);
        $otherToken = $otherUser->createToken('other-user-session');

        Sanctum::actingAs($owner);

        $this->deleteJson("/api/profile/sessions/{$otherToken->accessToken->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    public function test_cannot_revoke_current_session_via_revoke_endpoint(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $currentToken = $owner->createToken('current-session');
        Sanctum::actingAs($owner);
        // Sanctum::actingAs mockea un token propio; lo pisamos con el real
        // para que currentAccessToken() coincida con el que intentamos revocar.
        $owner->withAccessToken($currentToken->accessToken);

        $this->deleteJson("/api/profile/sessions/{$currentToken->accessToken->id}");

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
    }

    public function test_revoke_other_sessions_keeps_current(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $currentToken = $owner->createToken('current-session');
        $otherToken1 = $owner->createToken('device-1');
        $otherToken2 = $owner->createToken('device-2');
        Sanctum::actingAs($owner);
        $owner->withAccessToken($currentToken->accessToken);

        $this->deleteJson('/api/profile/sessions')->assertStatus(204);

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken1->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken2->accessToken->id]);
    }

    // -- Preferencias -------------------------------------------------------

    public function test_preferences_reject_unsupported_locale(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        Sanctum::actingAs($owner);

        $this->putJson('/api/profile/preferences', [
            'locale' => 'fr',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'date_format' => 'dd/MM/yyyy',
        ])->assertStatus(422);
    }

    public function test_preferences_are_saved_with_defaults_merged(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        Sanctum::actingAs($owner);

        $this->putJson('/api/profile/preferences', [
            'locale' => 'en',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'date_format' => 'MM/dd/yyyy',
        ])->assertOk()
            ->assertJsonPath('data.preferences.locale', 'en')
            ->assertJsonPath('data.preferences.date_format', 'MM/dd/yyyy');
    }

    // -- Trial vencido -------------------------------------------------------

    public function test_password_change_and_sessions_work_with_expired_trial_but_profile_update_does_not(): void
    {
        [$tenant, $owner] = $this->makeOwnerTenant();
        $tenant->update(['trial_ends_at' => now()->subDay()]);
        Sanctum::actingAs($owner);

        $this->putJson('/api/profile', ['name' => 'Otro Nombre'])
            ->assertStatus(402);

        $this->putJson('/api/profile/password', [
            'current_password' => 'password',
            'password' => 'brand-new-password-123',
            'password_confirmation' => 'brand-new-password-123',
        ])->assertOk();

        $this->getJson('/api/profile/sessions')->assertOk();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeOwnerTenant(): array
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('password'),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->syncRoles(['Owner']);

        return [$tenant, $user];
    }

    private function makeMember(Tenant $tenant): User
    {
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('password'),
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->syncRoles(['Member']);

        return $user;
    }
}
