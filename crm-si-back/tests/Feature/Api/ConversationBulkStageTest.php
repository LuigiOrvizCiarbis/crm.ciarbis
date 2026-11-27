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

class ConversationBulkStageTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $registrar->forgetCachedPermissions();

        $this->tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($this->tenant);
        $registrar->setPermissionsTeamId($this->tenant->id);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('Owner');
        Sanctum::actingAs($this->user);
    }

    public function test_moves_the_chat_opportunity_or_creates_one(): void
    {
        $stages = $this->defaultPipeline()->stages()->orderBy('sort_order')->get();
        $target = $stages[2];

        $withOpportunity = $this->createConversation($stages[0]);
        $opportunity = Opportunity::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $withOpportunity->contact_id,
            'conversation_id' => $withOpportunity->id,
            'pipeline_stage_id' => $stages[1]->id,
            'title' => 'Venta',
            'status' => 'open',
        ]);
        $withoutOpportunity = $this->createConversation($stages[0]);
        $group = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->createChannel()->id,
            'kind' => 'group',
            'status' => 'open',
            'pipeline_stage_id' => $stages[0]->id,
        ]);

        $this->postJson('/api/conversations/bulk-stage', [
            'ids' => [$withOpportunity->id, $withoutOpportunity->id, $group->id],
            'pipeline_stage_id' => $target->id,
        ])->assertOk()
            ->assertJsonPath('updated', 3)
            ->assertJsonPath('failed', 0);

        $this->assertSame($target->id, $opportunity->fresh()->pipeline_stage_id);
        $this->assertSame($target->id, $withOpportunity->fresh()->pipeline_stage_id);
        $this->assertSame(1, Opportunity::where('conversation_id', $withOpportunity->id)->count());

        $this->assertSame($target->id, $withoutOpportunity->fresh()->pipeline_stage_id);
        $this->assertSame(1, Opportunity::where('conversation_id', $withoutOpportunity->id)->where('pipeline_stage_id', $target->id)->count());

        $this->assertSame($target->id, $group->fresh()->pipeline_stage_id);
        $this->assertDatabaseMissing('opportunities', ['conversation_id' => $group->id]);
    }

    public function test_stage_must_belong_to_default_pipeline(): void
    {
        $renewals = Pipeline::create(['tenant_id' => $this->tenant->id, 'name' => 'Renovaciones', 'is_default' => false]);
        $stage = PipelineStage::create([
            'tenant_id' => $this->tenant->id,
            'pipeline_id' => $renewals->id,
            'name' => 'Contactado',
            'color' => '#000000',
            'sort_order' => 1,
        ]);
        $conversation = $this->createConversation($this->defaultPipeline()->entryStage());

        $this->postJson('/api/conversations/bulk-stage', [
            'ids' => [$conversation->id],
            'pipeline_stage_id' => $stage->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('opportunities', ['conversation_id' => $conversation->id]);
    }

    private function defaultPipeline(): Pipeline
    {
        return Pipeline::where('is_default', true)->sole();
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

    private function createConversation(PipelineStage $stage): Conversation
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Juan Pérez',
            'phone' => '+549'.fake()->numerify('##########'),
            'source' => 'whatsapp',
        ]);

        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->createChannel()->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'pipeline_stage_id' => $stage->id,
        ]);
    }
}
