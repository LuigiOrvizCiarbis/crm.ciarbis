<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardPipelineFilterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Contact $contact;

    private Pipeline $defaultPipeline;

    private PipelineStage $defaultStage;

    private Pipeline $secondPipeline;

    private PipelineStage $secondStage;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');

        $this->tenant = $this->createTenantWithRoles();
        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $this->owner->assignRole('Owner');

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ana Martinez',
            'source' => 'manual',
        ]);

        $this->defaultPipeline = Pipeline::defaultFor($this->tenant->id);
        $this->defaultStage = PipelineStage::withoutGlobalScopes()
            ->where('pipeline_id', $this->defaultPipeline->id)
            ->orderBy('sort_order')
            ->firstOrFail();

        $this->secondPipeline = Pipeline::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Postventa',
            'is_default' => false,
        ]);
        // Mismo nombre que la primera etapa del predeterminado: comparten clave en daily_series.
        $this->secondStage = PipelineStage::create([
            'tenant_id' => $this->tenant->id,
            'pipeline_id' => $this->secondPipeline->id,
            'name' => $this->defaultStage->name,
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

    public function test_default_selection_only_counts_default_pipeline(): void
    {
        $this->makeOpportunity($this->defaultStage, 'won', 100);
        $this->makeOpportunity($this->secondStage, 'won', 900);

        $response = $this->metrics()
            ->assertJsonPath('pipeline.id', $this->defaultPipeline->id)
            ->assertJsonPath('pipeline.name', $this->defaultPipeline->name)
            ->assertJsonPath('pipeline.is_default', true)
            ->assertJsonPath('kpis.total_opportunities', 1)
            ->assertJsonPath('kpis.won_count', 1);

        $this->assertEquals(100.0, $response->json('kpis.won_value'));

        $stages = collect($response->json('stages'));
        $this->assertNull($stages->firstWhere('id', $this->secondStage->id));
        $this->assertSame([$this->defaultPipeline->id], $stages->pluck('pipeline_id')->unique()->values()->all());
        $this->assertSame(1, $stages->firstWhere('id', $this->defaultStage->id)['count']);

        $this->metrics(['pipeline' => 'default'])->assertJsonPath('pipeline.id', $this->defaultPipeline->id);
    }

    public function test_specific_pipeline_returns_only_its_stages_and_kpis(): void
    {
        $this->makeOpportunity($this->defaultStage, 'won', 100);
        $this->makeOpportunity($this->secondStage, 'won', 900);

        $response = $this->metrics(['pipeline' => $this->secondPipeline->id])
            ->assertJsonPath('pipeline', [
                'id' => $this->secondPipeline->id,
                'name' => 'Postventa',
                'is_default' => false,
            ])
            ->assertJsonPath('kpis.total_opportunities', 1);

        $this->assertEquals(900.0, $response->json('kpis.won_value'));

        $stages = $response->json('stages');
        $this->assertCount(1, $stages);
        $this->assertSame($this->secondStage->id, $stages[0]['id']);
        $this->assertSame($this->secondPipeline->id, $stages[0]['pipeline_id']);
        $this->assertSame('Postventa', $stages[0]['pipeline_name']);
        $this->assertSame(1, $stages[0]['count']);
    }

    public function test_all_pipelines_returns_every_stage_and_combined_kpis(): void
    {
        $this->makeOpportunity($this->defaultStage, 'won', 100);
        $this->makeOpportunity($this->secondStage, 'won', 900);

        foreach (['todos', 'all'] as $value) {
            $response = $this->metrics(['pipeline' => $value])
                ->assertJsonPath('pipeline', null)
                ->assertJsonPath('kpis.total_opportunities', 2)
                ->assertJsonPath('kpis.won_count', 2);

            $this->assertEquals(1000.0, $response->json('kpis.won_value'));

            $stages = collect($response->json('stages'));
            // Predeterminado primero, después el resto.
            $this->assertSame($this->defaultPipeline->id, $stages->first()['pipeline_id']);
            $this->assertSame($this->defaultPipeline->name, $stages->first()['pipeline_name']);

            $second = $stages->firstWhere('id', $this->secondStage->id);
            $this->assertSame($this->secondPipeline->id, $second['pipeline_id']);
            $this->assertSame('Postventa', $second['pipeline_name']);
            $this->assertSame(1, $second['count']);
            $this->assertSame($this->secondStage->id, $stages->last()['id']);
        }
    }

    public function test_daily_series_sums_same_named_stages_across_pipelines(): void
    {
        $this->makeOpportunity($this->defaultStage, 'open', 100);
        $this->makeOpportunity($this->defaultStage, 'open', 100);
        $this->makeOpportunity($this->secondStage, 'open', 100);

        $key = $this->stageKey($this->defaultStage->name);
        $today = now()->toDateString();

        $all = collect($this->metrics(['pipeline' => 'todos'])->json('daily_series'))->firstWhere('date', $today);
        $this->assertSame(3, $all[$key]);

        $default = collect($this->metrics()->json('daily_series'))->firstWhere('date', $today);
        $this->assertSame(2, $default[$key]);

        $second = collect($this->metrics(['pipeline' => $this->secondPipeline->id])->json('daily_series'))->firstWhere('date', $today);
        $this->assertSame(1, $second[$key]);
    }

    public function test_cycle_time_respects_pipeline(): void
    {
        $this->makeOpportunity($this->defaultStage, 'won', 100, '2026-11-01 12:00:00', '2026-11-11 12:00:00');
        $this->makeOpportunity($this->secondStage, 'won', 100, '2026-11-01 12:00:00', '2026-11-03 12:00:00');

        $default = $this->metrics()->json('efficiency.cycle_time');
        $this->assertSame(1, $default['samples']);
        $this->assertEquals(10.0, $default['avg_days']);

        $second = $this->metrics(['pipeline' => $this->secondPipeline->id])->json('efficiency.cycle_time');
        $this->assertSame(1, $second['samples']);
        $this->assertEquals(2.0, $second['avg_days']);

        $all = $this->metrics(['pipeline' => 'todos'])->json('efficiency.cycle_time');
        $this->assertSame(2, $all['samples']);
        $this->assertEquals(6.0, $all['avg_days']);
    }

    public function test_foreign_or_invalid_pipeline_is_rejected(): void
    {
        $foreign = Pipeline::defaultFor(Tenant::create(['name' => 'Otra'])->id);

        Sanctum::actingAs($this->owner);

        $this->getJson('/api/dashboard/metrics?pipeline='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pipeline');

        $this->getJson('/api/dashboard/metrics?pipeline=inexistente')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pipeline');
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

    private function makeOpportunity(PipelineStage $stage, string $status, float $value, ?string $createdAt = null, ?string $closedAt = null): Opportunity
    {
        $opportunity = new Opportunity([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'pipeline_stage_id' => $stage->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Oportunidad',
            'status' => $status,
            'source_type' => 'manual',
            'value' => $value,
        ]);
        // Sin cierre explícito, el modelo estampa closed_at al guardar ganada o perdida.
        if ($closedAt !== null) {
            $opportunity->closed_at = $closedAt;
        }
        $opportunity->created_at = CarbonImmutable::parse($createdAt ?? now());
        $opportunity->save();

        return $opportunity;
    }

    private function stageKey(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?: 'stage', '_');
    }
}
