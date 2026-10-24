<?php

namespace Tests\Concerns;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Workspace con permisos Spatie sembrados, miembros por rol y una conversación. */
trait BuildsNotificationWorkspace
{
    protected function createWorkspace(): Tenant
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);

        return $tenant;
    }

    protected function addMember(Tenant $tenant, string $role = 'Owner', ?int $branchId = null): User
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['branch_id' => $branchId]);
        $user->assignRole($role);
        $user->unsetRelation('roles');

        return $user;
    }

    protected function createConversation(Tenant $tenant, User $channelOwner): Conversation
    {
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $channelOwner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '+5491111111111',
            'source' => 'whatsapp',
        ]);

        return Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }
}
