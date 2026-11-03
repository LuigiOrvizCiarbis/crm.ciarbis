<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Contact;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardStageBreakdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_breakdown_reports_open_and_stale_opportunities(): void
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');

        $defaultPipeline = Pipeline::defaultFor($tenant->id);
        [$firstStage, $secondStage] = PipelineStage::withoutGlobalScopes()
            ->where('pipeline_id', $defaultPipeline->id)
            ->orderBy('sort_order')
            ->take(2)
            ->get()
            ->all();

        $otherPipeline = Pipeline::create([
            'tenant_id' => $tenant->id,
            'name' => 'Postventa',
            'is_default' => false,
        ]);
        $otherStage = PipelineStage::create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $otherPipeline->id,
            'name' => 'Seguimiento',
            'color' => '#000000',
            'sort_order' => 1,
            'is_default' => true,
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana Martinez',
            'source' => 'manual',
        ]);

        $make = function (PipelineStage $stage, string $status, float $value, $lastActivity, $updatedAt) use ($tenant, $contact, $user): void {
            // created_at/updated_at no son fillable: se fuerzan para simular antigüedad.
            $opportunity = new Opportunity([
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'pipeline_stage_id' => $stage->id,
                'assigned_to' => $user->id,
                'title' => 'Oportunidad',
                'status' => $status,
                'source_type' => 'manual',
                'value' => $value,
                'last_activity_at' => $lastActivity,
            ]);
            $opportunity->forceFill(['created_at' => $updatedAt, 'updated_at' => $updatedAt])->save();
        };

        // Primera etapa: abierta al día, abierta estancada y ganada (no cuenta como abierta).
        $make($firstStage, 'open', 100, now()->subDays(2), now()->subDays(2));
        $make($firstStage, 'open', 200, now()->subDays(20), now()->subDays(1));
        $make($firstStage, 'won', 400, now()->subDays(30), now()->subDays(30));

        // Segunda etapa: sin actividad registrada, cae a updated_at.
        $make($secondStage, 'open', 50, null, now()->subDays(20));

        // Pipeline no predeterminado: fuera del Panel.
        $make($otherStage, 'open', 999, now()->subDays(20), now()->subDays(20));

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/metrics');

        $response->assertOk()->assertJsonPath('stale_after_days', 14);

        $stages = collect($response->json('stages'));

        $this->assertNull($stages->firstWhere('id', $otherStage->id));

        $first = $stages->firstWhere('id', $firstStage->id);
        $this->assertSame(3, $first['count']);
        $this->assertEquals(700.0, $first['value']);
        $this->assertSame(2, $first['open_count']);
        $this->assertEquals(300.0, $first['open_value']);
        $this->assertSame(1, $first['stale_count']);
        $this->assertEquals(200.0, $first['stale_value']);

        $second = $stages->firstWhere('id', $secondStage->id);
        $this->assertSame(1, $second['open_count']);
        $this->assertEquals(50.0, $second['open_value']);
        $this->assertSame(1, $second['stale_count']);
        $this->assertEquals(50.0, $second['stale_value']);
    }
}
