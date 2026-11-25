<?php

namespace Tests\Feature\Api;

use App\Ai\Tools\Assistant\ListTasks;
use App\Ai\Tools\Assistant\SearchContacts;
use App\Ai\Tools\Assistant\WorkspaceSummary;
use App\Models\Contact;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenantWithRoles();
    }

    public function test_list_tasks_only_returns_tasks_the_member_can_see(): void
    {
        $member = $this->userWithRole('Member');
        $teammate = $this->userWithRole('Member');
        $this->overdueTask('Mía', $member);
        $this->overdueTask('Del compañero', $teammate);
        Sanctum::actingAs($member);

        $result = json_decode((new ListTasks($member))->handle(new Request(['filter' => 'overdue', 'mine' => false])), true);

        $this->assertSame(1, $result['total']);
        $this->assertSame(['Mía'], array_column($result['tasks'], 'name'));
    }

    public function test_workspace_summary_does_not_count_other_workspaces(): void
    {
        $owner = $this->userWithRole('Owner');
        Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Ana', 'source' => 'manual']);
        Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Beto', 'source' => 'manual']);

        $other = $this->createTenantWithRoles('Otro');
        foreach (['X', 'Y', 'Z'] as $name) {
            Contact::create(['tenant_id' => $other->id, 'name' => $name, 'source' => 'manual']);
        }
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Sanctum::actingAs($owner);

        $result = json_decode((new WorkspaceSummary($owner))->handle(new Request), true);

        $this->assertSame(2, $result['totals']['contacts']);
        $this->assertSame('Acme', $result['workspace']['name']);
    }

    public function test_search_contacts_requires_two_characters(): void
    {
        $owner = $this->userWithRole('Owner');
        Sanctum::actingAs($owner);

        $this->assertSame(
            'Pasá al menos 2 caracteres para buscar.',
            (new SearchContacts($owner))->handle(new Request(['query' => 'a'])),
        );
    }

    private function userWithRole(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $user->assignRole($role);

        return $user;
    }

    private function overdueTask(string $name, User $assignee): void
    {
        Task::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'assigned_to' => $assignee->id,
            'deadline' => now()->subDay(),
        ]);
    }
}
