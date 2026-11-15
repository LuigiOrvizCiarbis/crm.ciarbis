<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PipelineStageOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->createTenantWithRoles();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user->assignRole('Owner');
        Sanctum::actingAs($this->user);
    }

    public function test_moving_to_won_stage_closes_opportunity_and_leaving_it_reopens(): void
    {
        $opportunity = Opportunity::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->createContact()->id,
            'pipeline_stage_id' => $this->stage('Lead/Prospecto')->id,
            'title' => 'Venta',
            'status' => 'open',
            'source_type' => 'manual',
        ]);

        $this->patchJson("/api/opportunities/{$opportunity->id}/stage", ['pipeline_stage_id' => $this->stage('Cliente Convertido/Ganado')->id])
            ->assertOk();
        $opportunity->refresh();
        $this->assertSame('won', $opportunity->status);
        $this->assertNotNull($opportunity->closed_at);

        $this->patchJson("/api/opportunities/{$opportunity->id}/stage", ['pipeline_stage_id' => $this->stage('Negociación')->id])
            ->assertOk();
        $opportunity->refresh();
        $this->assertSame('open', $opportunity->status);
        $this->assertNull($opportunity->closed_at);
    }

    public function test_leaving_won_stage_keeps_status_the_stage_did_not_set(): void
    {
        foreach (['archived', 'lost'] as $status) {
            $opportunity = Opportunity::create([
                'tenant_id' => $this->tenant->id,
                'contact_id' => $this->createContact()->id,
                'pipeline_stage_id' => $this->stage('Cliente Convertido/Ganado')->id,
                'title' => 'Venta',
                'source_type' => 'manual',
            ]);
            $opportunity->update(['status' => $status]);
            $closedAt = $opportunity->fresh()->closed_at;

            $this->patchJson("/api/opportunities/{$opportunity->id}/stage", ['pipeline_stage_id' => $this->stage('Negociación')->id])
                ->assertOk();

            $opportunity->refresh();
            $this->assertSame($status, $opportunity->status);
            $this->assertEquals($closedAt, $opportunity->closed_at);
            $this->assertNotNull($opportunity->closed_at);
        }
    }

    public function test_moving_chat_to_won_stage_creates_won_opportunity(): void
    {
        $conversation = Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->createChannel()->id,
            'contact_id' => $this->createContact()->id,
            'status' => 'open',
        ]);

        $this->patchJson("/api/conversations/{$conversation->id}/stage", ['pipeline_stage_id' => $this->stage('Cliente Convertido/Ganado')->id])
            ->assertOk();

        $opportunity = Opportunity::where('conversation_id', $conversation->id)->sole();
        $this->assertSame('won', $opportunity->status);
        $this->assertNotNull($opportunity->closed_at);
    }

    public function test_default_stages_carry_their_outcome(): void
    {
        $outcomes = PipelineStage::where('tenant_id', $this->tenant->id)->pluck('outcome', 'name');

        $this->assertSame('won', $outcomes['Cliente Convertido/Ganado']);
        $this->assertSame('lost', $outcomes['Perdido/No le interesa']);
        $this->assertSame(
            [],
            $outcomes->except(['Cliente Convertido/Ganado', 'Perdido/No le interesa'])->filter()->all(),
        );
    }

    public function test_stage_outcome_can_be_updated_and_is_validated(): void
    {
        $stage = $this->stage('Negociación');

        $this->putJson("/api/pipeline-stages/{$stage->id}", ['outcome' => 'lost'])
            ->assertOk()
            ->assertJsonPath('outcome', 'lost');

        $this->putJson("/api/pipeline-stages/{$stage->id}", ['outcome' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outcome');
    }

    private function stage(string $name): PipelineStage
    {
        return PipelineStage::where('tenant_id', $this->tenant->id)->where('name', $name)->sole();
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
}
