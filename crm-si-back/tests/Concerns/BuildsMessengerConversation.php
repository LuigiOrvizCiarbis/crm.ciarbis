<?php

namespace Tests\Concerns;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessengerConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Tenant con roles provisionados + conversación de Messenger (PAGE_1/PSID_1),
 * el canal más simple de fakear con Http::fake.
 */
trait BuildsMessengerConversation
{
    /**
     * @return array{0: User, 1: Conversation, 2: Tenant}
     */
    private function createMessengerConversation(string $role = 'Owner', ChannelType $type = ChannelType::FACEBOOK): array
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        $config = MessengerConfig::create([
            'tenant_id' => $tenant->id,
            'page_id' => 'PAGE_1',
            'page_name' => 'Acme SRL',
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
        ]);

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'messenger_config_id' => $config->id,
            'type' => $type,
            'external_id' => 'PAGE_1',
            'name' => 'Acme SRL',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Fulano FB',
            'source' => 'facebook',
            'external_id' => 'PSID_1',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        return [$user, $conversation, $tenant];
    }

    private function seedTenantWithRoles(): Tenant
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        return $tenant;
    }
}
