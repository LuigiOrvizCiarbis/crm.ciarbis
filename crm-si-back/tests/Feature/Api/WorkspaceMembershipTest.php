<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class WorkspaceMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_switches_context_without_changing_legacy_user_assignment(): void
    {
        $first = $this->createTenantWithRoles('Primero');
        $second = $this->createTenantWithRoles('Segundo');
        $user = User::factory()->create(['tenant_id' => $first->id]);
        TenantMembership::create(['tenant_id' => $second->id, 'user_id' => $user->id, 'joined_at' => now()]);
        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Workspace-Id', (string) $second->id)->getJson('/api/user')->assertOk();

        $response->assertJsonPath('user.tenant.id', $second->id);
        $this->assertSame($first->id, $user->fresh()->tenant_id);
    }

    public function test_user_cannot_select_a_workspace_without_membership(): void
    {
        $tenant = $this->createTenantWithRoles();
        $other = $this->createTenantWithRoles('Otro');
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user);

        $this->withHeader('X-Workspace-Id', (string) $other->id)
            ->getJson('/api/user')
            ->assertForbidden();
    }

    public function test_contact_requests_stay_in_the_selected_workspace(): void
    {
        $first = $this->createTenantWithRoles('Primero');
        $second = $this->createTenantWithRoles('Segundo');
        $user = User::factory()->create(['tenant_id' => $first->id]);
        TenantMembership::create(['tenant_id' => $second->id, 'user_id' => $user->id, 'joined_at' => now()]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($first->id);
        $user->assignRole('Owner');
        $registrar->setPermissionsTeamId($second->id);
        $user->assignRole('Owner');

        $firstContact = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $first->id,
            'name' => 'Contacto primero',
            'source' => 'manual',
            'custom_data' => [],
        ]);
        $secondContact = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $second->id,
            'name' => 'Contacto segundo',
            'source' => 'manual',
            'custom_data' => [],
        ]);

        Sanctum::actingAs($user);

        // Debe ser el primer request: así el test comprueba que el workspace
        // se resuelve antes del route model binding y no depende de una request
        // previa que haya mutado el User autenticado en memoria.
        $this->withHeader('X-Workspace-Id', (string) $second->id)
            ->putJson("/api/contacts/{$secondContact->id}", ['name' => 'Contacto actualizado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Contacto actualizado');

        $this->withHeader('X-Workspace-Id', (string) $second->id)
            ->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $secondContact->id);

        $this->withHeader('X-Workspace-Id', (string) $second->id)
            ->putJson("/api/contacts/{$firstContact->id}", ['name' => 'No debe actualizar'])
            ->assertNotFound();
    }

    public function test_accepting_an_invitation_adds_membership_without_abandoning_current_workspace(): void
    {
        $source = $this->createTenantWithRoles('Origen');
        $target = $this->createTenantWithRoles('Destino');
        $user = User::factory()->create(['tenant_id' => $source->id]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($source->id);
        $user->syncRoles(['Owner']);

        $invitation = Invitation::withoutGlobalScopes()->create([
            'tenant_id' => $target->id,
            'email' => $user->email,
            'token' => str_repeat('a', 64),
            'role_name' => 'Member',
            'invited_by' => $user->id,
            'expires_at' => now()->addDay(),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/invitations/accept', ['token' => $invitation->token])
            ->assertOk()
            ->assertJsonPath('workspace.id', $target->id);

        $this->assertTrue(TenantMembership::active()->where('tenant_id', $source->id)->where('user_id', $user->id)->exists());
        $this->assertTrue(TenantMembership::active()->where('tenant_id', $target->id)->where('user_id', $user->id)->exists());
        $this->assertSame($source->id, $user->fresh()->tenant_id);
    }

    public function test_verified_user_can_create_an_additional_workspace_without_trial(): void
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/workspaces', ['name' => 'Nuevo equipo'])->assertCreated();
        $workspaceId = $response->json('data.id');

        $this->assertTrue(TenantMembership::active()->where('tenant_id', $workspaceId)->where('user_id', $user->id)->exists());
        $this->assertNull(Tenant::findOrFail($workspaceId)->trial_ends_at);
    }

    public function test_member_removal_requires_the_permission_in_the_target_workspace(): void
    {
        $source = $this->createTenantWithRoles('Origen');
        $target = $this->createTenantWithRoles('Destino');
        $actor = User::factory()->create(['tenant_id' => $source->id]);
        $member = User::factory()->create(['tenant_id' => $target->id]);
        TenantMembership::create(['tenant_id' => $target->id, 'user_id' => $actor->id, 'joined_at' => now()]);

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($source->id);
        $actor->syncRoles(['Owner']); // Owners have users.deactivate in the selected workspace.
        $registrar->setPermissionsTeamId($target->id);
        $actor->syncRoles(['Member']); // But not in the workspace being mutated.

        Sanctum::actingAs($actor);

        $this->withHeader('X-Workspace-Id', (string) $source->id)
            ->deleteJson("/api/workspaces/{$target->id}/members/{$member->id}")
            ->assertForbidden();

        $this->assertTrue(TenantMembership::active()
            ->where('tenant_id', $target->id)
            ->where('user_id', $member->id)
            ->exists());
    }
}
