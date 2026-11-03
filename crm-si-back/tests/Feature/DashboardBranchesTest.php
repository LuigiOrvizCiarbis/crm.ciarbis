<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardBranchesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $seller;

    private Branch $centro;

    private Branch $norte;

    private Channel $whatsapp;

    private Channel $instagram;

    private PipelineStage $defaultStage;

    private PipelineStage $secondStage;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');

        $this->tenant = $this->createTenantWithRoles();
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::ADMIN]);
        $this->owner->assignRole('Owner');
        $this->seller = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->centro = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro']);
        $this->norte = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Norte']);

        $this->whatsapp = $this->channel(ChannelType::WHATSAPP, 'WhatsApp');
        $this->instagram = $this->channel(ChannelType::INSTAGRAM, 'Instagram');

        $defaultPipeline = Pipeline::defaultFor($this->tenant->id);
        $this->defaultStage = PipelineStage::withoutGlobalScopes()
            ->where('pipeline_id', $defaultPipeline->id)
            ->orderBy('sort_order')
            ->firstOrFail();
        $second = Pipeline::create(['tenant_id' => $this->tenant->id, 'name' => 'Postventa', 'is_default' => false]);
        $this->secondStage = PipelineStage::create([
            'tenant_id' => $this->tenant->id,
            'pipeline_id' => $second->id,
            'name' => 'Seguimiento',
            'color' => '#000000',
            'sort_order' => 1,
            'is_default' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_opportunities_follow_the_selected_pipeline(): void
    {
        $this->opportunity($this->centro, $this->defaultStage, 'won', 100);
        $this->opportunity($this->centro, $this->secondStage, 'won', 900);
        $this->opportunity($this->norte, $this->secondStage, 'open', 50);

        $this->assertSame(
            ['Centro' => [1, 100.0, 1], 'Norte' => [0, 0.0, 0]],
            $this->opportunityColumns($this->branches()),
        );
        $this->assertSame(
            ['Centro' => [1, 900.0, 1], 'Norte' => [1, 50.0, 0]],
            $this->opportunityColumns($this->branches(['pipeline' => $this->secondStage->pipeline_id])),
        );
        $this->assertSame(
            ['Centro' => [2, 1000.0, 2], 'Norte' => [1, 50.0, 0]],
            $this->opportunityColumns($this->branches(['pipeline' => 'todos'])),
        );
    }

    public function test_period_bounds_every_column(): void
    {
        $this->contact($this->centro, '2026-11-02');
        $this->contact($this->centro, '2026-09-10');
        $this->conversation($this->centro, $this->whatsapp, '2026-11-03');
        $this->conversation($this->centro, $this->whatsapp, '2026-09-10');
        $this->opportunity($this->centro, $this->defaultStage, 'open', 10, '2026-11-04');
        $this->opportunity($this->centro, $this->defaultStage, 'open', 20, '2026-09-10');

        $row = $this->row($this->branches(['periodo' => 'este-mes']), 'Centro');
        $this->assertSame([1, 1, 1], [$row['contacts_new'], $row['conversations'], $row['opportunities_count']]);

        $row = $this->row($this->branches(['periodo' => 'este-ano']), 'Centro');
        $this->assertSame([2, 2, 2], [$row['contacts_new'], $row['conversations'], $row['opportunities_count']]);
    }

    public function test_channel_and_salesperson_filters_apply(): void
    {
        $viaWhatsapp = $this->conversation($this->centro, $this->whatsapp);
        $viaInstagram = $this->conversation($this->centro, $this->instagram, assignedTo: $this->seller);
        $this->opportunity($this->centro, $this->defaultStage, 'open', 10, conversation: $viaWhatsapp);
        $this->opportunity($this->centro, $this->defaultStage, 'open', 30, conversation: $viaInstagram, assignedTo: $this->seller);

        $row = $this->row($this->branches(['canal' => $this->instagram->id]), 'Centro');
        $this->assertSame([1, 1, 30.0], [$row['conversations'], $row['opportunities_count'], (float) $row['opportunities_value']]);

        $row = $this->row($this->branches(['owner' => $this->seller->id]), 'Centro');
        $this->assertSame([1, 1, 30.0], [$row['conversations'], $row['opportunities_count'], (float) $row['opportunities_value']]);
    }

    public function test_branch_rows_add_up_to_the_panel_kpis(): void
    {
        $this->opportunity($this->centro, $this->defaultStage, 'won', 100);
        $this->opportunity($this->norte, $this->defaultStage, 'open', 40);
        $this->conversation($this->centro, $this->whatsapp);
        $this->conversation($this->norte, $this->instagram);

        $rows = $this->branches()->json('data');
        $kpis = $this->getJson('/api/dashboard/metrics?periodo=este-mes')->assertOk()->json('kpis');

        $this->assertSame($kpis['total_opportunities'], array_sum(array_column($rows, 'opportunities_count')));
        $this->assertEquals($kpis['pipeline_value'], array_sum(array_column($rows, 'opportunities_value')));
        $this->assertSame($kpis['won_count'], array_sum(array_column($rows, 'opportunities_won')));
        $this->assertSame($kpis['total_conversations'], array_sum(array_column($rows, 'conversations')));
    }

    public function test_foreign_pipeline_is_rejected(): void
    {
        $foreign = Pipeline::defaultFor($this->createTenantWithRoles()->id);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/dashboard/branches?pipeline='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pipeline');
    }

    public function test_branch_filter_scopes_every_panel_metric(): void
    {
        $centroUser = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->assignBranch($centroUser, $this->centro);
        $this->assignBranch($this->seller, $this->norte);

        $this->contact($this->centro);
        $this->conversation($this->centro, $this->whatsapp);
        $this->opportunity($this->centro, $this->defaultStage, 'won', 100);
        $this->task($centroUser, TaskStatus::DONE);

        $this->contact($this->norte);
        $this->conversation($this->norte, $this->whatsapp);
        $this->conversation($this->norte, $this->instagram);
        $this->opportunity($this->norte, $this->defaultStage, 'won', 900);
        $this->task($this->seller, TaskStatus::NEW);

        $centro = $this->metrics(['sucursal' => $this->centro->id]);
        $this->assertSame(
            [1, 1, 1, 1],
            [$centro->json('kpis.total_opportunities'), $centro->json('kpis.won_count'), $centro->json('kpis.total_conversations'), $centro->json('kpis.total_contacts')],
        );
        $this->assertEquals(100, $centro->json('kpis.won_value'));
        $this->assertSame(1, collect($centro->json('stages'))->firstWhere('id', $this->defaultStage->id)['count']);
        $this->assertSame([1, 1], [$centro->json('efficiency.tasks.total'), $centro->json('efficiency.tasks.completed')]);
        $this->assertSame(1, $centro->json('efficiency.cycle_time.samples'));
        $this->assertSame(1, array_sum(array_column($centro->json('omnichannel'), 'conversations_count')));

        $all = $this->metrics();
        $this->assertSame([2, 3, 2], [$all->json('kpis.total_opportunities'), $all->json('kpis.total_conversations'), $all->json('efficiency.tasks.total')]);
    }

    public function test_branch_comparison_ignores_the_branch_filter(): void
    {
        $this->opportunity($this->centro, $this->defaultStage, 'open', 10);
        $this->opportunity($this->norte, $this->defaultStage, 'open', 20);

        $this->assertSame(
            ['Centro' => [1, 10.0, 0], 'Norte' => [1, 20.0, 0]],
            $this->opportunityColumns($this->branches(['sucursal' => $this->centro->id])),
        );
    }

    public function test_branch_bound_user_cannot_read_another_branch(): void
    {
        $this->assignBranch($this->seller, $this->norte);
        $this->seller->givePermissionTo('analytics.view');
        $this->opportunity($this->centro, $this->defaultStage, 'open', 10, assignedTo: $this->seller);
        $this->opportunity($this->norte, $this->defaultStage, 'open', 20, assignedTo: $this->seller);

        Sanctum::actingAs($this->seller);
        $this->getJson('/api/dashboard/metrics?sucursal='.$this->centro->id)->assertOk()->assertJsonPath('kpis.total_opportunities', 0);
        $this->getJson('/api/dashboard/metrics')->assertOk()->assertJsonPath('kpis.total_opportunities', 1);
    }

    public function test_foreign_branch_is_rejected(): void
    {
        $foreign = Branch::create(['tenant_id' => $this->createTenantWithRoles('Otra')->id, 'name' => 'Ajena']);

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/dashboard/metrics?sucursal='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sucursal');
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function metrics(array $query = []): TestResponse
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/dashboard/metrics?'.http_build_query(['periodo' => 'este-mes', ...$query]))
            ->assertOk();
    }

    /** La sucursal de un usuario sale de su membresía al workspace, no de users.branch_id. */
    private function assignBranch(User $user, Branch $branch): void
    {
        TenantMembership::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $user->id)
            ->update(['branch_id' => $branch->id]);
    }

    private function task(User $assignee, TaskStatus $status): void
    {
        Task::create([
            'tenant_id' => $this->tenant->id,
            'assigned_to' => $assignee->id,
            'name' => 'Seguimiento',
            'status' => $status->value,
            'deadline' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function branches(array $query = []): TestResponse
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/dashboard/branches?'.http_build_query(['periodo' => 'este-mes', ...$query]))
            ->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TestResponse $response, string $name): array
    {
        return collect($response->json('data'))->firstWhere('name', $name);
    }

    /**
     * @return array<string, array{0: int, 1: float, 2: int}>
     */
    private function opportunityColumns(TestResponse $response): array
    {
        return collect($response->json('data'))
            ->mapWithKeys(fn (array $row) => [$row['name'] => [
                $row['opportunities_count'],
                (float) $row['opportunities_value'],
                $row['opportunities_won'],
            ]])
            ->all();
    }

    private function channel(ChannelType $type, string $name): Channel
    {
        return Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'type' => $type,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function contact(Branch $branch, ?string $createdAt = null): Contact
    {
        $contact = new Contact(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'name' => 'Ana', 'source' => 'manual']);
        $contact->created_at = CarbonImmutable::parse($createdAt ?? now());
        $contact->save();

        return $contact;
    }

    private function conversation(Branch $branch, Channel $channel, ?string $createdAt = null, ?User $assignedTo = null): Conversation
    {
        $conversation = new Conversation([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'channel_id' => $channel->id,
            'contact_id' => $this->contact($branch, '2020-01-01')->id,
            'assigned_to' => $assignedTo?->id,
            'status' => 'open',
        ]);
        $conversation->created_at = CarbonImmutable::parse($createdAt ?? now());
        $conversation->save();

        return $conversation;
    }

    private function opportunity(
        Branch $branch,
        PipelineStage $stage,
        string $status,
        float $value,
        ?string $createdAt = null,
        ?Conversation $conversation = null,
        ?User $assignedTo = null,
    ): Opportunity {
        $opportunity = new Opportunity([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'contact_id' => $conversation?->contact_id ?? $this->contact($branch, '2020-01-01')->id,
            'conversation_id' => $conversation?->id,
            'pipeline_stage_id' => $stage->id,
            'assigned_to' => ($assignedTo ?? $this->owner)->id,
            'title' => 'Oportunidad',
            'status' => $status,
            'source_type' => 'manual',
            'value' => $value,
        ]);
        $opportunity->created_at = CarbonImmutable::parse($createdAt ?? now());
        $opportunity->save();

        return $opportunity;
    }
}
