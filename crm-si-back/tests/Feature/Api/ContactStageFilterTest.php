<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContactStageFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_contacts_by_stage_id(): void
    {
        [$user, $tenant] = $this->createUser('Owner');
        $nuevo = $this->makeStage($tenant, 'Nuevo');
        $propuesta = $this->makeStage($tenant, 'Propuesta');

        $inNuevo = $this->makeContact($tenant, 'En nuevo');
        $inPropuesta = $this->makeContact($tenant, 'En propuesta');
        $this->makeContact($tenant, 'Sin oportunidad');
        $this->makeOpportunity($inNuevo, $nuevo);
        $this->makeOpportunity($inPropuesta, $propuesta);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/contacts?stages={$nuevo->id}")->assertOk();

        $this->assertSame([$inNuevo->id], $this->ids($response->json('data')));
    }

    public function test_none_matches_contacts_without_listed_opportunities(): void
    {
        [$user, $tenant] = $this->createUser('Owner');
        $stage = $this->makeStage($tenant, 'Nuevo');

        $withOpportunity = $this->makeContact($tenant, 'Con oportunidad');
        $withoutOpportunity = $this->makeContact($tenant, 'Sin oportunidad');
        $onlyArchived = $this->makeContact($tenant, 'Solo archivada');
        $this->makeOpportunity($withOpportunity, $stage);
        $this->makeOpportunity($onlyArchived, $stage, ['status' => 'archived']);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/contacts?stages=none')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$withoutOpportunity->id, $onlyArchived->id],
            $this->ids($response->json('data')),
        );
    }

    public function test_stage_ids_and_none_combine_with_or(): void
    {
        [$user, $tenant] = $this->createUser('Owner');
        $nuevo = $this->makeStage($tenant, 'Nuevo');
        $propuesta = $this->makeStage($tenant, 'Propuesta');

        $inNuevo = $this->makeContact($tenant, 'En nuevo');
        $inPropuesta = $this->makeContact($tenant, 'En propuesta');
        $without = $this->makeContact($tenant, 'Sin oportunidad');
        $this->makeOpportunity($inNuevo, $nuevo);
        $this->makeOpportunity($inPropuesta, $propuesta);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/contacts?stages={$nuevo->id},none")->assertOk();

        $this->assertEqualsCanonicalizing([$inNuevo->id, $without->id], $this->ids($response->json('data')));
    }

    public function test_archived_opportunities_do_not_match_their_stage(): void
    {
        [$user, $tenant] = $this->createUser('Owner');
        $stage = $this->makeStage($tenant, 'Nuevo');

        $archived = $this->makeContact($tenant, 'Archivada');
        $this->makeOpportunity($archived, $stage, ['status' => 'archived']);

        Sanctum::actingAs($user);

        $this->getJson("/api/contacts?stages={$stage->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.opportunities', []);
    }

    public function test_contacts_include_listed_opportunities_newest_first(): void
    {
        [$user, $tenant] = $this->createUser('Owner');
        $stage = $this->makeStage($tenant, 'Propuesta', '#ff0000');
        $contact = $this->makeContact($tenant, 'Ana');

        $older = $this->makeOpportunity($contact, $stage, ['title' => 'Vieja', 'value' => 1500]);
        $newer = $this->makeOpportunity($contact, null, ['title' => 'Nueva']);
        $this->makeOpportunity($contact, $stage, ['title' => 'Archivada', 'status' => 'archived']);
        DB::table('opportunities')->where('id', $older->id)->update(['updated_at' => now()->subDay()]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/contacts')->assertOk();

        $opportunities = $response->json('data.0.opportunities');
        $this->assertSame([$newer->id, $older->id], array_column($opportunities, 'id'));
        $this->assertNull($opportunities[0]['pipeline_stage']);

        $item = $opportunities[1];
        $this->assertSame('Vieja', $item['title']);
        $this->assertSame($stage->pipeline_id, $item['pipeline_id']);
        $this->assertSame(Pipeline::find($stage->pipeline_id)->name, $item['pipeline_name']);
        $this->assertNull($item['conversation_id']);
        $this->assertSame('open', $item['status']);
        $this->assertEquals(1500, $item['value']);
        $this->assertNotNull($item['created_at']);
        $this->assertNotNull($item['updated_at']);
        $this->assertSame([
            'id' => $stage->id,
            'name' => 'Propuesta',
            'color' => '#ff0000',
            'outcome' => null,
        ], $item['pipeline_stage']);

        $this->getJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.opportunities.0.id', $newer->id)
            ->assertJsonPath('data.opportunities.1.pipeline_stage.id', $stage->id)
            ->assertJsonCount(2, 'data.opportunities');
    }

    public function test_member_only_sees_opportunities_assigned_to_them(): void
    {
        [$owner, $tenant] = $this->createUser('Owner');
        $member = $this->makeMember($tenant);
        $mine = $this->makeStage($tenant, 'Mía');
        $theirs = $this->makeStage($tenant, 'Ajena');

        $contact = $this->makeContact($tenant, 'Compartido');
        $assigned = $this->makeOpportunity($contact, $mine, ['assigned_to' => $member->id]);
        $this->makeOpportunity($contact, $theirs, ['assigned_to' => $owner->id]);

        Sanctum::actingAs($member);

        $response = $this->getJson('/api/contacts')->assertOk();
        $this->assertSame([$assigned->id], array_column($response->json('data.0.opportunities'), 'id'));

        $this->getJson("/api/contacts?stages={$theirs->id}")->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/contacts/stage-counts')
            ->assertOk()
            ->assertExactJson(['data' => [(string) $mine->id => 1, 'none' => 0]]);
    }

    public function test_stage_counts_count_distinct_contacts_and_respect_other_filters(): void
    {
        [$user, $tenant] = $this->createUser('Owner');
        $nuevo = $this->makeStage($tenant, 'Nuevo');
        $propuesta = $this->makeStage($tenant, 'Propuesta');
        $vip = $this->makeTag($tenant, $user, 'VIP');

        $twoInNuevo = $this->makeContact($tenant, 'Dos en nuevo', $vip);
        $this->makeOpportunity($twoInNuevo, $nuevo);
        $this->makeOpportunity($twoInNuevo, $nuevo);
        $this->makeOpportunity($twoInNuevo, $propuesta);

        $oneInNuevo = $this->makeContact($tenant, 'Uno en nuevo', $vip);
        $this->makeOpportunity($oneInNuevo, $nuevo);
        $this->makeOpportunity($oneInNuevo, $propuesta, ['status' => 'archived']);

        $this->makeContact($tenant, 'VIP sin oportunidad', $vip);

        $untagged = $this->makeContact($tenant, 'Sin etiqueta');
        $this->makeOpportunity($untagged, $propuesta);
        $this->makeContact($tenant, 'Sin etiqueta ni oportunidad');

        Sanctum::actingAs($user);

        $this->getJson("/api/contacts/stage-counts?tags={$vip->slug}&stages={$propuesta->id}")
            ->assertOk()
            ->assertExactJson(['data' => [
                (string) $nuevo->id => 2,
                (string) $propuesta->id => 1,
                'none' => 1,
            ]]);

        $this->getJson('/api/contacts/stage-counts')
            ->assertOk()
            ->assertExactJson(['data' => [
                (string) $nuevo->id => 2,
                (string) $propuesta->id => 2,
                'none' => 2,
            ]]);
    }

    public function test_stage_filter_and_counts_are_tenant_isolated(): void
    {
        [$user, $tenant] = $this->createUser('Owner');
        [, $otherTenant] = $this->createUser('Owner');
        $stage = $this->makeStage($tenant, 'Nuevo');
        $foreignStage = $this->makeStage($otherTenant, 'Ajena');

        $own = $this->makeContact($tenant, 'Propio');
        $this->makeOpportunity($own, $stage);
        $foreign = $this->makeContact($otherTenant, 'Ajeno');
        $this->makeOpportunity($foreign, $foreignStage);
        $this->makeContact($otherTenant, 'Ajeno sin oportunidad');

        Sanctum::actingAs($user);

        $this->getJson("/api/contacts?stages={$foreignStage->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson("/api/contacts?stages={$foreignStage->id},none")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/contacts/stage-counts')
            ->assertOk()
            ->assertExactJson(['data' => [(string) $stage->id => 1, 'none' => 0]]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contacts
     * @return list<int>
     */
    private function ids(array $contacts): array
    {
        return array_column($contacts, 'id');
    }

    private function createUser(string $role): array
    {
        $tenant = $this->seedTenantWithRoles();

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

    private function makeStage(Tenant $tenant, string $name, string $color = '#64748b'): PipelineStage
    {
        return PipelineStage::create([
            'tenant_id' => $tenant->id,
            'pipeline_id' => Pipeline::defaultFor($tenant->id)->id,
            'name' => $name,
            'color' => $color,
            'sort_order' => 99,
        ]);
    }

    private function makeContact(Tenant $tenant, string $name, ?Tag $tag = null): Contact
    {
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'phone' => '+549'.fake()->numerify('##########'),
            'source' => 'manual',
        ]);

        $tag?->contacts()->attach($contact->id, ['tenant_id' => $tenant->id, 'assigned_by' => $tag->created_by]);

        return $contact;
    }

    private function makeOpportunity(Contact $contact, ?PipelineStage $stage, array $attributes = []): Opportunity
    {
        return Opportunity::create([
            'tenant_id' => $contact->tenant_id,
            'contact_id' => $contact->id,
            'pipeline_stage_id' => $stage?->id,
            'title' => 'Oportunidad',
            'status' => 'open',
            ...$attributes,
        ]);
    }

    private function makeTag(Tenant $tenant, User $creator, string $name): Tag
    {
        return Tag::create([
            'tenant_id' => $tenant->id,
            'created_by' => $creator->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.uniqid(),
            'color' => '#64748b',
        ]);
    }
}
