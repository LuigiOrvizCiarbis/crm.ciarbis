<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TaskListTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_task_includes_the_related_contact_name(): void
    {
        [$tenant, $user] = $this->createTenantAndOwner();
        Sanctum::actingAs($user);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Juan Brunatti',
            'source' => 'whatsapp',
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp principal',
            'status' => 'active',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_content' => 'Hola Juan, ¿cómo estás?',
        ]);
        Task::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'assigned_to' => $user->id,
            'name' => 'Llamar al lead',
            'deadline' => '2026-08-10 15:00:00',
        ]);

        $this->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.conversation.contact.name', 'Juan Brunatti')
            ->assertJsonPath('data.0.assigned_user.name', $user->name);
    }

    /** @return array{Tenant, User} */
    private function createTenantAndOwner(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        return [$tenant, $user];
    }
}
