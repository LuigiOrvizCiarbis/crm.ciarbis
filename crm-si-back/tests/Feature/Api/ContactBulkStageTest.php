<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContactBulkStageTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_the_open_opportunity_in_the_stage_pipeline_or_creates_one(): void
    {
        [$owner, $tenant] = $this->createUser('Owner');
        Sanctum::actingAs($owner);

        $from = $this->makeStage($tenant, Pipeline::defaultFor($tenant->id), 'Nuevo');
        $target = $this->makeStage($tenant, Pipeline::defaultFor($tenant->id), 'Propuesta');
        $otherPipeline = Pipeline::create(['tenant_id' => $tenant->id, 'name' => 'Postventa', 'is_default' => false]);
        $otherStage = $this->makeStage($tenant, $otherPipeline, 'Soporte');

        $withOpen = $this->makeContact($tenant, 'Con abierta');
        $open = $this->makeOpportunity($withOpen, $from);
        $lost = $this->makeOpportunity($withOpen, $from, ['status' => 'lost']);
        $lost->forceFill(['updated_at' => now()->addMinute()])->saveQuietly();

        $elsewhere = $this->makeContact($tenant, 'En otro embudo');
        $foreign = $this->makeOpportunity($elsewhere, $otherStage);

        $without = $this->makeContact($tenant, 'Sin oportunidad');

        $this->postJson('/api/contacts/bulk-stage', [
            'ids' => [$withOpen->id, $elsewhere->id, $without->id],
            'pipeline_stage_id' => $target->id,
        ])->assertOk()
            ->assertJsonPath('updated', 3)
            ->assertJsonPath('failed', 0);

        $this->assertSame($target->id, $open->fresh()->pipeline_stage_id);
        $this->assertSame($from->id, $lost->fresh()->pipeline_stage_id);
        $this->assertSame($otherStage->id, $foreign->fresh()->pipeline_stage_id);
        $this->assertSame(1, $withOpen->opportunities()->where('pipeline_stage_id', $target->id)->count());
        $this->assertSame(1, $elsewhere->opportunities()->where('pipeline_stage_id', $target->id)->count());
        $this->assertSame(1, $without->opportunities()->where('pipeline_stage_id', $target->id)->count());
    }

    public function test_won_value_only_fills_opportunities_without_one(): void
    {
        [$owner, $tenant] = $this->createUser('Owner');
        Sanctum::actingAs($owner);

        $pipeline = Pipeline::defaultFor($tenant->id);
        $from = $this->makeStage($tenant, $pipeline, 'Nuevo');
        $won = $this->makeStage($tenant, $pipeline, 'Ganado ok', ['outcome' => PipelineStage::OUTCOME_WON]);

        $priced = $this->makeOpportunity($this->makeContact($tenant, 'Con monto'), $from, ['value' => 500]);
        $unpriced = $this->makeOpportunity($this->makeContact($tenant, 'Sin monto'), $from);

        $this->postJson('/api/contacts/bulk-stage', [
            'ids' => [$priced->contact_id, $unpriced->contact_id],
            'pipeline_stage_id' => $won->id,
            'value' => 120,
        ])->assertOk()->assertJsonPath('updated', 2);

        $this->assertSame('won', $priced->fresh()->status);
        $this->assertEquals(500, $priced->fresh()->value);
        $this->assertEquals(120, $unpriced->fresh()->value);
    }

    public function test_contacts_outside_the_member_scope_fail_without_blocking_the_rest(): void
    {
        [$owner, $tenant] = $this->createUser('Owner');
        $member = $this->makeMember($tenant);
        $pipeline = Pipeline::defaultFor($tenant->id);
        $from = $this->makeStage($tenant, $pipeline, 'Nuevo');
        $target = $this->makeStage($tenant, $pipeline, 'Propuesta');

        $assigned = $this->makeOpportunity($this->makeContact($tenant, 'Mío'), $from, ['assigned_to' => $member->id]);
        $foreign = $this->makeOpportunity($this->makeContact($tenant, 'Ajeno'), $from, ['assigned_to' => $owner->id]);

        Sanctum::actingAs($member);

        $this->postJson('/api/contacts/bulk-stage', [
            'ids' => [$assigned->contact_id, $foreign->contact_id],
            'pipeline_stage_id' => $target->id,
        ])->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('failed', 1)
            ->assertJsonPath('failures.0.id', $foreign->contact_id);

        $this->assertSame($target->id, $assigned->fresh()->pipeline_stage_id);
        $this->assertSame($from->id, $foreign->fresh()->pipeline_stage_id);
    }

    public function test_rejects_stages_from_another_tenant(): void
    {
        [$owner, $tenant] = $this->createUser('Owner');
        [, $otherTenant] = $this->createUser('Owner');
        $foreignStage = $this->makeStage($otherTenant, Pipeline::defaultFor($otherTenant->id), 'Ajena');

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Sanctum::actingAs($owner);

        $this->postJson('/api/contacts/bulk-stage', [
            'ids' => [$this->makeContact($tenant, 'Uno')->id],
            'pipeline_stage_id' => $foreignStage->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('pipeline_stage_id');
    }

    private function createUser(string $role): array
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

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole($role);

        return [$user, $tenant];
    }

    private function makeMember(Tenant $tenant): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');

        return $member;
    }

    private function makeStage(Tenant $tenant, Pipeline $pipeline, string $name, array $attributes = []): PipelineStage
    {
        return PipelineStage::create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => $name,
            'color' => '#64748b',
            'sort_order' => 99,
            ...$attributes,
        ]);
    }

    private function makeContact(Tenant $tenant, string $name): Contact
    {
        return Contact::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'phone' => '+549'.fake()->numerify('##########'),
            'source' => 'manual',
        ]);
    }

    private function makeOpportunity(Contact $contact, PipelineStage $stage, array $attributes = []): Opportunity
    {
        return Opportunity::create([
            'tenant_id' => $contact->tenant_id,
            'contact_id' => $contact->id,
            'pipeline_stage_id' => $stage->id,
            'title' => 'Oportunidad',
            'status' => 'open',
            ...$attributes,
        ]);
    }
}
