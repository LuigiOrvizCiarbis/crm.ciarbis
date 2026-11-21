<?php

namespace Tests\Feature\Api;

use App\Ai\Agents\WorkspaceAssistant;
use App\Ai\Tools\Assistant\CreateTask;
use App\Ai\Tools\Assistant\ListTasks;
use App\Ai\Tools\Assistant\SearchContacts;
use App\Ai\Tools\Assistant\WorkspaceSummary;
use App\Models\Contact;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tasks\TaskCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Ai\Tools\Request;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
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

    public function test_create_task_asks_confirmation_with_resolved_details_and_creates_after_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-21 12:00', 'America/Argentina/Buenos_Aires'));
        $owner = $this->userWithRole('Owner');
        // Ciudad de México es UTC-6; la app, UTC-3.
        $owner->update(['preferences' => ['timezone' => 'America/Mexico_City']]);
        $contact = Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Jorge Merlo', 'source' => 'manual']);
        Sanctum::actingAs($owner);
        $tool = new CreateTask($owner, app(TaskCreator::class));
        $request = new Request([
            'name' => 'Llamar a Jorge',
            'deadline' => '2026-11-22 10:00',
            'contact_id' => $contact->id,
            'priority' => 'alta',
        ]);

        $approval = $tool->shouldRequestApproval($request);
        $this->assertNotNull($approval);
        $this->assertStringContainsString('Contacto: Jorge Merlo', $approval->reason);
        // Se muestra en la zona del usuario (10:00), no en la de la app (13:00).
        $this->assertStringContainsString('22/11 10:00', $approval->reason);
        $this->assertSame(0, Task::withoutGlobalScopes()->count());

        $result = json_decode($tool->handle($request), true);

        $task = Task::withoutGlobalScopes()->findOrFail($result['task']['id']);
        $this->assertSame($owner->id, $task->assigned_to);
        $this->assertSame($contact->id, $task->contact_id);
        $this->assertSame($this->tenant->id, $task->tenant_id);
        $this->assertSame('2026-11-22 13:00', $task->deadline->format('Y-m-d H:i'));
    }

    public function test_create_task_refuses_contacts_the_member_cannot_see_without_asking(): void
    {
        $member = $this->userWithRole('Member');
        $stranger = Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Ajeno', 'source' => 'manual']);
        Sanctum::actingAs($member);
        $tool = new CreateTask($member, app(TaskCreator::class));
        $request = new Request(['name' => 'Llamar', 'contact_id' => $stranger->id]);

        $this->assertNull($tool->shouldRequestApproval($request));
        $this->assertStringContainsString('No encontré ese contacto', $tool->handle($request));
        $this->assertSame(0, Task::withoutGlobalScopes()->count());
    }

    public function test_create_task_is_not_offered_without_permission(): void
    {
        $member = $this->userWithRole('Member');
        Sanctum::actingAs($member);
        $agent = fn () => new WorkspaceAssistant($member, [], 'model');
        $toolNames = fn () => array_map(fn ($tool) => $tool->name(), [...$agent()->tools()]);

        $this->assertContains('create_task', $toolNames());

        Role::query()->where('tenant_id', $this->tenant->id)->where('name', 'Member')->firstOrFail()
            ->revokePermissionTo('tasks.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $member->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertNotContains('create_task', $toolNames());
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
