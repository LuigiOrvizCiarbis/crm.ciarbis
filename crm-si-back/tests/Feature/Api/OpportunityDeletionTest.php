<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OpportunityDeletionTest extends TestCase
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

    public function test_deleting_the_chat_opportunity_falls_back_to_the_remaining_one_then_clears_the_stage(): void
    {
        $conversation = $this->createConversation();
        $older = $this->createOpportunity($conversation, 'Interesado');
        $this->travel(1)->minutes();
        $newer = $this->createOpportunity($conversation, 'Negociación');
        $this->assertSame($newer->pipeline_stage_id, $conversation->fresh()->pipeline_stage_id);

        $this->deleteJson("/api/opportunities/{$newer->id}")->assertOk();
        $this->assertSame($older->pipeline_stage_id, $conversation->fresh()->pipeline_stage_id);

        $this->deleteJson("/api/opportunities/{$older->id}")->assertOk();
        $this->assertNull($conversation->fresh()->pipeline_stage_id);
    }

    public function test_deleting_detaches_its_tags(): void
    {
        $opportunity = $this->createOpportunity($this->createConversation(), 'Interesado');
        $tag = Tag::create([
            'tenant_id' => $this->tenant->id,
            'created_by' => $this->user->id,
            'name' => 'VIP',
            'slug' => 'vip',
            'color' => '#64748b',
        ]);
        $opportunity->tags()->attach($tag->id, ['tenant_id' => $this->tenant->id, 'assigned_by' => $this->user->id]);

        $this->deleteJson("/api/opportunities/{$opportunity->id}")->assertOk();

        $this->assertSame(0, DB::table('taggables')->where('taggable_type', Opportunity::class)->count());
    }

    private function createOpportunity(Conversation $conversation, string $stageName): Opportunity
    {
        return Opportunity::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->id,
            'pipeline_stage_id' => PipelineStage::where('tenant_id', $this->tenant->id)->where('name', $stageName)->sole()->id,
            'title' => 'Venta',
            'source_type' => 'conversation',
        ]);
    }

    private function createConversation(): Conversation
    {
        $channel = Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Juan Pérez',
            'phone' => '+549'.fake()->numerify('##########'),
            'source' => 'whatsapp',
        ]);

        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }
}
