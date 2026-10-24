<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
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

class MultiPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->seedTenantWithRoles('Acme');
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('Owner');
        Sanctum::actingAs($this->user);
    }

    public function test_new_tenant_gets_default_pipeline_with_its_stages(): void
    {
        $pipeline = Pipeline::where('tenant_id', $this->tenant->id)->sole();

        $this->assertTrue($pipeline->is_default);
        $this->assertSame(
            PipelineStage::where('tenant_id', $this->tenant->id)->count(),
            $pipeline->stages()->count(),
        );
    }

    public function test_board_lists_only_stages_and_opportunities_of_selected_pipeline(): void
    {
        $default = $this->defaultPipeline();
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $contact = $this->createContact();

        $this->createOpportunity($contact, $default->entryStage(), 'Venta inicial');
        $this->createOpportunity($contact, $renewals->entryStage(), 'Renovación anual');

        $this->getJson("/api/pipeline-stages?pipeline_id={$renewals->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Contactado');

        $titles = collect($this->getJson("/api/opportunities?pipeline_id={$renewals->id}")->assertOk()->json('data'))->pluck('title');
        $this->assertEquals(['Renovación anual'], $titles->all());
    }

    public function test_stage_list_without_pipeline_returns_default_pipeline_only(): void
    {
        $this->createPipelineWithStage('Renovaciones', 'Contactado');

        $names = collect($this->getJson('/api/pipeline-stages')->assertOk()->json())->pluck('name');

        $this->assertFalse($names->contains('Contactado'));
        $this->assertSame($this->defaultPipeline()->stages()->count(), $names->count());
    }

    public function test_create_opportunity_in_chosen_pipeline_defaults_to_its_entry_stage(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $contact = $this->createContact();

        $this->postJson('/api/opportunities', [
            'contact_id' => $contact->id,
            'pipeline_id' => $renewals->id,
            'title' => 'Renovación',
            'value' => 1500,
        ])->assertCreated()
            ->assertJsonPath('data.pipeline_id', $renewals->id)
            ->assertJsonPath('data.pipeline_stage_id', $renewals->entryStage()->id);
    }

    public function test_create_opportunity_rejects_stage_from_another_pipeline(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');

        $this->postJson('/api/opportunities', [
            'contact_id' => $this->createContact()->id,
            'pipeline_id' => $renewals->id,
            'pipeline_stage_id' => $this->defaultPipeline()->entryStage()->id,
        ])->assertStatus(422);
    }

    public function test_contact_can_have_several_opportunities_in_the_same_pipeline(): void
    {
        $contact = $this->createContact();
        $stage = $this->defaultPipeline()->entryStage();

        $this->postJson('/api/opportunities', ['contact_id' => $contact->id, 'pipeline_stage_id' => $stage->id, 'title' => 'Producto A'])->assertCreated();
        $this->postJson('/api/opportunities', ['contact_id' => $contact->id, 'pipeline_stage_id' => $stage->id, 'title' => 'Producto B'])->assertCreated();

        $this->assertSame(2, Opportunity::where('contact_id', $contact->id)->count());
    }

    public function test_moving_opportunity_to_another_pipeline_requires_stage_of_that_pipeline(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $opportunity = $this->createOpportunity($this->createContact(), $this->defaultPipeline()->entryStage());

        $this->patchJson("/api/opportunities/{$opportunity->id}/stage", [
            'pipeline_id' => $renewals->id,
            'pipeline_stage_id' => $this->defaultPipeline()->entryStage()->id,
        ])->assertStatus(422);

        $this->patchJson("/api/opportunities/{$opportunity->id}/stage", [
            'pipeline_id' => $renewals->id,
            'pipeline_stage_id' => $renewals->entryStage()->id,
        ])->assertOk()->assertJsonPath('data.pipeline_id', $renewals->id);
    }

    public function test_moving_default_pipeline_opportunity_moves_only_it_and_syncs_chat_stage(): void
    {
        $contact = $this->createContact();
        $stages = $this->defaultPipeline()->stages()->orderBy('sort_order')->get();
        $conversation = $this->createConversation($contact, $stages[0]);
        $first = $this->createOpportunity($contact, $stages[0], 'Primera', $conversation);
        $second = $this->createOpportunity($contact, $stages[0], 'Segunda', $conversation);

        $this->patchJson("/api/opportunities/{$second->id}/stage", ['pipeline_stage_id' => $stages[2]->id])->assertOk();

        $this->assertSame($stages[0]->id, $first->fresh()->pipeline_stage_id);
        $this->assertSame($stages[2]->id, $second->fresh()->pipeline_stage_id);
        $this->assertSame($stages[2]->id, $conversation->fresh()->pipeline_stage_id);
    }

    public function test_creating_default_pipeline_opportunity_syncs_chat_stage(): void
    {
        $contact = $this->createContact();
        $stages = $this->defaultPipeline()->stages()->orderBy('sort_order')->get();
        $conversation = $this->createConversation($contact, $stages[0]);

        $this->postJson('/api/opportunities', [
            'contact_id' => $contact->id,
            'conversation_id' => $conversation->id,
            'pipeline_stage_id' => $stages[1]->id,
        ])->assertCreated();

        $this->assertSame($stages[1]->id, $conversation->fresh()->pipeline_stage_id);
    }

    public function test_other_pipeline_opportunity_does_not_touch_chat_stage(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $contact = $this->createContact();
        $chatStage = $this->defaultPipeline()->entryStage();
        $conversation = $this->createConversation($contact, $chatStage);
        $opportunity = $this->createOpportunity($contact, $renewals->entryStage(), 'Renovación', $conversation);

        $second = PipelineStage::create(['tenant_id' => $this->tenant->id, 'pipeline_id' => $renewals->id, 'name' => 'Cerrado', 'sort_order' => 2]);
        $this->patchJson("/api/opportunities/{$opportunity->id}/stage", ['pipeline_stage_id' => $second->id])->assertOk();

        $this->assertSame($chatStage->id, $conversation->fresh()->pipeline_stage_id);
    }

    public function test_chat_stage_is_locked_while_chat_has_default_pipeline_opportunity(): void
    {
        $contact = $this->createContact();
        $stages = $this->defaultPipeline()->stages()->orderBy('sort_order')->get();
        $conversation = $this->createConversation($contact, $stages[0]);

        $this->patchJson("/api/conversations/{$conversation->id}/stage", ['pipeline_stage_id' => $stages[1]->id])->assertOk();
        $this->assertSame($stages[1]->id, $conversation->fresh()->pipeline_stage_id);

        $this->createOpportunity($contact, $stages[1], 'Venta', $conversation);

        $this->patchJson("/api/conversations/{$conversation->id}/stage", ['pipeline_stage_id' => $stages[2]->id])->assertStatus(422);
        $this->assertSame($stages[1]->id, $conversation->fresh()->pipeline_stage_id);
    }

    public function test_chat_stage_must_belong_to_default_pipeline(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $conversation = $this->createConversation($this->createContact(), $this->defaultPipeline()->entryStage());

        $this->patchJson("/api/conversations/{$conversation->id}/stage", ['pipeline_stage_id' => $renewals->entryStage()->id])->assertStatus(422);
    }

    public function test_opportunities_can_be_filtered_by_conversation(): void
    {
        $contact = $this->createContact();
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->createChannel()->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $this->createOpportunity($contact, $this->defaultPipeline()->entryStage(), 'Del chat', $conversation);
        $this->createOpportunity($contact, $this->defaultPipeline()->entryStage(), 'Sin chat');

        $titles = collect($this->getJson("/api/opportunities?conversation_id={$conversation->id}")->assertOk()->json('data'))->pluck('title');

        $this->assertEquals(['Del chat'], $titles->all());
    }

    public function test_pipeline_with_opportunities_cannot_be_deleted(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $opportunity = $this->createOpportunity($this->createContact(), $renewals->entryStage());

        $this->deleteJson("/api/pipelines/{$renewals->id}")->assertStatus(422);

        $opportunity->delete();
        $this->deleteJson("/api/pipelines/{$renewals->id}")->assertOk();
        $this->assertDatabaseMissing('pipelines', ['id' => $renewals->id]);
    }

    public function test_pipeline_with_chats_in_its_stages_cannot_be_deleted(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $conversation = $this->createConversation($this->createContact(), $renewals->entryStage());

        $this->deleteJson("/api/pipelines/{$renewals->id}")->assertStatus(422);
        $this->assertSame($renewals->entryStage()->id, $conversation->fresh()->pipeline_stage_id);

        $conversation->update(['pipeline_stage_id' => $this->defaultPipeline()->entryStage()->id]);
        $this->deleteJson("/api/pipelines/{$renewals->id}")->assertOk();
    }

    public function test_default_pipeline_cannot_switch_while_chats_have_its_stages(): void
    {
        $previous = $this->defaultPipeline();
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $conversation = $this->createConversation($this->createContact(), $previous->entryStage());

        $this->putJson("/api/pipelines/{$renewals->id}", ['is_default' => true])->assertStatus(422);
        $this->postJson('/api/pipelines', ['name' => 'Otro', 'is_default' => true])->assertStatus(422);
        $this->assertTrue($previous->fresh()->is_default);
        $this->assertDatabaseMissing('pipelines', ['name' => 'Otro']);

        // Renombrar sigue permitido: sólo se bloquea el cambio de predeterminado.
        $this->putJson("/api/pipelines/{$renewals->id}", ['name' => 'Renovaciones 2026'])->assertOk();

        $conversation->update(['pipeline_stage_id' => null]);
        $this->putJson("/api/pipelines/{$renewals->id}", ['is_default' => true])->assertOk();
        $this->assertTrue($renewals->fresh()->is_default);
    }

    public function test_switching_default_reconciles_chats_without_stage_silently(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $closing = PipelineStage::create(['tenant_id' => $this->tenant->id, 'pipeline_id' => $renewals->id, 'name' => 'Cerrando', 'sort_order' => 2]);
        $contact = $this->createContact();

        $single = Conversation::create(['tenant_id' => $this->tenant->id, 'channel_id' => $this->createChannel()->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $this->createOpportunity($contact, $renewals->entryStage(), 'Única', $single);

        $double = Conversation::create(['tenant_id' => $this->tenant->id, 'channel_id' => $this->createChannel()->id, 'contact_id' => $contact->id, 'status' => 'open']);
        $older = $this->createOpportunity($contact, $renewals->entryStage(), 'Vieja', $double);
        $older->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
        $this->createOpportunity($contact, $closing, 'Nueva', $double);

        $untouched = Conversation::create(['tenant_id' => $this->tenant->id, 'channel_id' => $this->createChannel()->id, 'contact_id' => $contact->id, 'status' => 'open']);

        $stageChanges = 0;
        Conversation::updated(function (Conversation $conversation) use (&$stageChanges): void {
            if ($conversation->wasChanged('pipeline_stage_id')) {
                $stageChanges++;
            }
        });

        $this->putJson("/api/pipelines/{$renewals->id}", ['is_default' => true])->assertOk();

        $this->assertSame($renewals->entryStage()->id, $single->fresh()->pipeline_stage_id);
        $this->assertSame($closing->id, $double->fresh()->pipeline_stage_id);
        $this->assertNull($untouched->fresh()->pipeline_stage_id);
        $this->assertSame(0, $stageChanges, 'La reconciliación no debe disparar stage_changed');
    }

    public function test_pipeline_without_stages_cannot_become_default(): void
    {
        $empty = Pipeline::create(['tenant_id' => $this->tenant->id, 'name' => 'Vacío', 'is_default' => false]);

        $this->putJson("/api/pipelines/{$empty->id}", ['is_default' => true])->assertStatus(422);
        $this->postJson('/api/pipelines', ['name' => 'Nuevo vacío', 'is_default' => true])->assertStatus(422);
        $this->assertFalse($empty->fresh()->is_default);
        $this->assertDatabaseMissing('pipelines', ['name' => 'Nuevo vacío']);
    }

    public function test_last_stage_of_default_pipeline_cannot_be_deleted(): void
    {
        $default = $this->defaultPipeline();
        $stages = $default->stages()->orderBy('sort_order')->get();
        foreach ($stages->slice(1) as $stage) {
            $this->deleteJson("/api/pipeline-stages/{$stage->id}")->assertNoContent();
        }

        $this->deleteJson("/api/pipeline-stages/{$stages->first()->id}")->assertStatus(422);
        $this->assertSame(1, $default->stages()->count());

        // En un pipeline no predeterminado sí se puede vaciar.
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $this->deleteJson("/api/pipeline-stages/{$renewals->entryStage()->id}")->assertNoContent();
    }

    public function test_default_pipeline_cannot_be_deleted_or_unset(): void
    {
        $default = $this->defaultPipeline();

        $this->deleteJson("/api/pipelines/{$default->id}")->assertStatus(422);
        $this->putJson("/api/pipelines/{$default->id}", ['is_default' => false])->assertStatus(422);
        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_marking_pipeline_as_default_unsets_the_previous_one(): void
    {
        $previous = $this->defaultPipeline();
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');

        $this->putJson("/api/pipelines/{$renewals->id}", ['is_default' => true])->assertOk();

        $this->assertFalse($previous->fresh()->is_default);
        $this->assertTrue($renewals->fresh()->is_default);
    }

    public function test_pipeline_names_are_unique_per_tenant(): void
    {
        $this->postJson('/api/pipelines', ['name' => 'Ventas'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/api/pipelines', ['name' => 'Postventa'])->assertCreated()->assertJsonPath('is_default', false);
    }

    public function test_pipeline_can_be_created_with_initial_stages(): void
    {
        $response = $this->postJson('/api/pipelines', [
            'name' => 'Renovaciones',
            'stages' => [
                ['name' => 'Contactado', 'color' => '#22C55E'],
                ['name' => 'Negociando'],
                ['name' => 'Renovado', 'color' => '#3B82F6'],
            ],
        ])->assertCreated()->assertJsonPath('stages_count', 3);

        $stages = PipelineStage::where('pipeline_id', $response->json('id'))->orderBy('sort_order')->get();

        $this->assertSame(['Contactado', 'Negociando', 'Renovado'], $stages->pluck('name')->all());
        $this->assertSame([1, 2, 3], $stages->pluck('sort_order')->all());
        $this->assertTrue((bool) $stages[0]->is_default);
        $this->assertFalse((bool) $stages[1]->is_default);
        $this->assertSame('#22C55E', $stages[0]->color);
        $this->assertSame($this->tenant->id, $stages[0]->tenant_id);
    }

    public function test_pipeline_creation_rejects_invalid_stages_atomically(): void
    {
        $this->postJson('/api/pipelines', [
            'name' => 'Roto',
            'stages' => [['name' => 'A'], ['name' => 'A']],
        ])->assertStatus(422)->assertJsonValidationErrors('stages.1.name');

        $this->postJson('/api/pipelines', [
            'name' => 'Roto',
            'stages' => [['name' => 'A', 'color' => 'rojo']],
        ])->assertStatus(422)->assertJsonValidationErrors('stages.0.color');

        $this->assertDatabaseMissing('pipelines', ['name' => 'Roto']);
    }

    public function test_other_tenant_pipeline_is_rejected(): void
    {
        $otherTenant = $this->seedTenantWithRoles('Otra');
        $foreignPipeline = Pipeline::withoutGlobalScopes()->where('tenant_id', $otherTenant->id)->sole();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $this->postJson('/api/opportunities', [
            'contact_id' => $this->createContact()->id,
            'pipeline_id' => $foreignPipeline->id,
        ])->assertStatus(422)->assertJsonValidationErrors('pipeline_id');

        $this->deleteJson("/api/pipelines/{$foreignPipeline->id}")->assertNotFound();
    }

    public function test_opportunity_created_without_pipeline_follows_its_stage_or_default(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $contact = $this->createContact();

        $withStage = $this->createOpportunity($contact, $renewals->entryStage());
        $withoutStage = Opportunity::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'title' => 'Sin etapa',
            'status' => 'open',
        ]);

        $this->assertSame($renewals->id, $withStage->pipeline_id);
        $this->assertSame($this->defaultPipeline()->id, $withoutStage->pipeline_id);
    }

    public function test_summary_can_be_scoped_to_a_pipeline(): void
    {
        $renewals = $this->createPipelineWithStage('Renovaciones', 'Contactado');
        $contact = $this->createContact();
        $this->createOpportunity($contact, $this->defaultPipeline()->entryStage());
        $this->createOpportunity($contact, $renewals->entryStage());

        $this->getJson('/api/opportunities/summary')->assertOk()->assertJsonPath('total_opportunities', 2);
        $this->getJson("/api/opportunities/summary?pipeline_id={$renewals->id}")->assertOk()->assertJsonPath('total_opportunities', 1);
    }

    private function defaultPipeline(): Pipeline
    {
        return Pipeline::where('is_default', true)->sole();
    }

    private function createPipelineWithStage(string $name, string $stageName): Pipeline
    {
        $pipeline = Pipeline::create(['tenant_id' => $this->tenant->id, 'name' => $name, 'is_default' => false]);
        PipelineStage::create([
            'tenant_id' => $this->tenant->id,
            'pipeline_id' => $pipeline->id,
            'name' => $stageName,
            'color' => '#000000',
            'sort_order' => 1,
        ]);

        return $pipeline;
    }

    private function createContact(): Contact
    {
        return Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Juan Pérez',
            'phone' => '+549'.fake()->numerify('##########'),
            'source' => 'whatsapp',
        ]);
    }

    private function createChannel(): Channel
    {
        return Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);
    }

    private function createConversation(Contact $contact, PipelineStage $stage): Conversation
    {
        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->createChannel()->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'pipeline_stage_id' => $stage->id,
        ]);
    }

    private function createOpportunity(Contact $contact, PipelineStage $stage, string $title = 'Oportunidad', ?Conversation $conversation = null): Opportunity
    {
        return Opportunity::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'conversation_id' => $conversation?->id,
            'pipeline_stage_id' => $stage->id,
            'title' => $title,
            'status' => 'open',
            'source_type' => 'manual',
        ]);
    }

    private function seedTenantWithRoles(string $name): Tenant
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => $name.' '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        return $tenant;
    }
}
