<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/dashboard/metrics')->assertStatus(401);
    }

    public function test_authenticated_request_returns_expected_payload_shape(): void
    {
        $tenant = $this->createTenantWithRoles();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/metrics');

        $response->assertOk()
            ->assertJsonStructure([
                'stages',
                'kpis' => [
                    'total_opportunities',
                    'pipeline_value',
                    'weighted_value',
                    'avg_value',
                    'total_conversations',
                    'total_contacts',
                    'won_count',
                    'won_value',
                    'global_conversion_rate',
                    'avg_roi',
                ],
                'previous',
                'trends',
                'daily_series',
                'omnichannel',
                'range' => ['start', 'end', 'periodo'],
            ]);
    }

    public function test_stage_breakdown_includes_conversations_without_linked_opportunity(): void
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');
        $stage = PipelineStage::where('tenant_id', $tenant->id)
            ->where('name', 'Capturados')
            ->firstOrFail();
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana Martinez',
            'source' => 'whatsapp',
        ]);
        $conversationWithOpportunity = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'pipeline_stage_id' => $stage->id,
            'last_message_at' => now()->subMonths(2),
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ]);

        Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'pipeline_stage_id' => $stage->id,
            'last_message_at' => now()->subMonths(2),
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ]);

        Opportunity::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'conversation_id' => $conversationWithOpportunity->id,
            'pipeline_stage_id' => $stage->id,
            'assigned_to' => $user->id,
            'title' => 'Venta desde WhatsApp',
            'status' => 'open',
            'source_type' => 'conversation',
            'value' => 1250,
            'last_activity_at' => now()->subMonths(2),
            'created_at' => now()->subMonths(2),
            'updated_at' => now()->subMonths(2),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/dashboard/metrics');

        $response->assertOk();

        $capturados = collect($response->json('stages'))->firstWhere('id', $stage->id);

        $this->assertSame(2, $capturados['count']);
        $this->assertEquals(1250.0, $capturados['value']);
    }

    public function test_invalid_periodo_is_rejected(): void
    {
        $tenant = $this->createTenantWithRoles();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::ADMIN,
        ]);
        $user->assignRole('Owner');

        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard/metrics?periodo=invalido')
            ->assertStatus(422)
            ->assertJsonValidationErrors('periodo');
    }
}
