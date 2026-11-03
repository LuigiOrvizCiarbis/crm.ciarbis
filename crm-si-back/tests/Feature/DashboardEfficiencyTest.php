<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Contact $contact;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');

        $this->tenant = $this->createTenantWithRoles();
        $this->owner = $this->makeUser('Owner');
        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Ana Martinez',
            'source' => 'whatsapp',
        ]);
        $this->channel = Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'WhatsApp',
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_response_time_counts_only_human_replies(): void
    {
        $conversation = $this->makeConversation();

        // Turno 1: el bot responde al minuto, la persona a los 10.
        $this->message($conversation, 'contact', 'inbound', '2026-11-10 10:00:00');
        $this->message($conversation, 'system', 'outbound', '2026-11-10 10:01:00');
        $this->message($conversation, 'user', 'outbound', '2026-11-10 10:10:00');

        // Turno 2: dos mensajes seguidos del contacto, cuenta desde el primero (20 min).
        $this->message($conversation, 'contact', 'inbound', '2026-11-11 09:00:00');
        $this->message($conversation, 'contact', 'inbound', '2026-11-11 09:05:00');
        $this->message($conversation, 'user', 'outbound', '2026-11-11 09:20:00');

        // Turno sin respuesta: excluido.
        $this->message($conversation, 'contact', 'inbound', '2026-11-12 09:00:00');
        $this->message($conversation, 'system', 'outbound', '2026-11-12 09:02:00');

        $efficiency = $this->efficiency();

        $this->assertSame(2, $efficiency['response_time']['samples']);
        $this->assertEquals(15.0, $efficiency['response_time']['avg_minutes']);
        $this->assertNull($efficiency['response_time']['previous_avg_minutes']);
        $this->assertNull($efficiency['response_time']['goal_minutes']);
    }

    public function test_response_time_uses_turn_start_for_range_and_computes_previous(): void
    {
        $conversation = $this->makeConversation();

        // Arranca en el período anterior y se responde ya dentro del actual: cuenta para el anterior.
        $this->message($conversation, 'contact', 'inbound', '2026-10-31 23:50:00');
        $this->message($conversation, 'user', 'outbound', '2026-11-01 00:20:00');

        // Turno anterior completo de 6 min en otra conversación.
        $other = $this->makeConversation();
        $this->message($other, 'contact', 'inbound', '2026-10-20 10:00:00');
        $this->message($other, 'user', 'outbound', '2026-10-20 10:06:00');

        $efficiency = $this->efficiency();

        $this->assertSame(0, $efficiency['response_time']['samples']);
        $this->assertNull($efficiency['response_time']['avg_minutes']);
        $this->assertEquals(18.0, $efficiency['response_time']['previous_avg_minutes']);
    }

    public function test_response_time_ignores_soft_deleted_messages(): void
    {
        $conversation = $this->makeConversation();
        $this->message($conversation, 'contact', 'inbound', '2026-11-10 10:00:00');
        $this->message($conversation, 'user', 'outbound', '2026-11-10 10:05:00', deleted: true);
        $this->message($conversation, 'user', 'outbound', '2026-11-10 10:30:00');

        $this->assertEquals(30.0, $this->efficiency()['response_time']['avg_minutes']);
    }

    public function test_cycle_time_averages_won_opportunities_closed_in_range(): void
    {
        $this->makeOpportunity('won', '2026-11-01 12:00:00', '2026-11-11 12:00:00');
        $this->makeOpportunity('won', '2026-10-15 12:00:00', '2026-11-05 00:00:00');
        $this->makeOpportunity('lost', '2026-10-01 12:00:00', '2026-11-05 12:00:00');
        $this->makeOpportunity('won', '2026-10-01 12:00:00', '2026-10-20 12:00:00');

        $cycle = $this->efficiency()['cycle_time'];

        $this->assertSame(2, $cycle['samples']);
        // (10 + 20.5) / 2
        $this->assertEquals(15.3, $cycle['avg_days']);
        $this->assertEquals(19.0, $cycle['previous_avg_days']);
    }

    public function test_status_change_stamps_and_clears_closed_at(): void
    {
        $opportunity = $this->makeOpportunity('open', '2026-11-01 12:00:00');
        $this->assertNull($opportunity->closed_at);

        $opportunity->update(['status' => 'won']);
        $this->assertTrue($opportunity->fresh()->closed_at->equalTo(now()));

        $opportunity->update(['status' => 'archived']);
        $this->assertNotNull($opportunity->fresh()->closed_at);

        $opportunity->update(['status' => 'open']);
        $this->assertNull($opportunity->fresh()->closed_at);

        $opportunity->update(['status' => 'lost', 'closed_at' => '2026-11-02 08:00:00']);
        $this->assertSame('2026-11-02 08:00:00', $opportunity->fresh()->closed_at->toDateTimeString());
    }

    public function test_tasks_count_completed_over_deadline_range_and_respect_owner(): void
    {
        $seller = $this->makeUser('Member');

        $this->makeTask(TaskStatus::DONE, '2026-11-05 10:00:00', $seller);
        $this->makeTask(TaskStatus::NEW, '2026-11-20 10:00:00', $seller);
        $this->makeTask(TaskStatus::CANCELED, '2026-11-06 10:00:00', $seller);
        $this->makeTask(TaskStatus::DONE, '2026-11-07 10:00:00', $this->owner);
        $this->makeTask(TaskStatus::DONE, '2026-10-20 10:00:00', $seller);
        $this->makeTask(TaskStatus::DONE, '2026-12-02 10:00:00', $seller);

        $this->assertSame(
            ['completed' => 2, 'total' => 3, 'previous_completed' => 1, 'previous_total' => 1],
            $this->efficiency()['tasks'],
        );

        $this->assertSame(
            ['completed' => 1, 'total' => 2, 'previous_completed' => 1, 'previous_total' => 1],
            $this->efficiency(['owner' => $seller->id])['tasks'],
        );
    }

    public function test_goals_default_to_null(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/dashboard/goals')
            ->assertOk()
            ->assertExactJson(['data' => ['response_time_minutes' => null, 'cycle_time_days' => null, 'revenue_monthly' => null]]);
    }

    public function test_owner_updates_goals_and_metrics_reflect_them(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/dashboard/goals', ['response_time_minutes' => 15, 'cycle_time_days' => 18])
            ->assertOk()
            ->assertExactJson(['data' => ['response_time_minutes' => 15, 'cycle_time_days' => 18, 'revenue_monthly' => null]]);

        $this->getJson('/api/dashboard/goals')
            ->assertJsonPath('data.response_time_minutes', 15);

        $efficiency = $this->efficiency();
        $this->assertSame(15, $efficiency['response_time']['goal_minutes']);
        $this->assertSame(18, $efficiency['cycle_time']['goal_days']);

        $this->putJson('/api/dashboard/goals', ['response_time_minutes' => null, 'cycle_time_days' => 18])
            ->assertOk()
            ->assertJsonPath('data.response_time_minutes', null);
    }

    public function test_member_without_team_permission_cannot_update_goals(): void
    {
        Sanctum::actingAs($this->makeUser('Member'));

        $this->getJson('/api/dashboard/goals')->assertOk();
        $this->putJson('/api/dashboard/goals', ['response_time_minutes' => 15, 'cycle_time_days' => 18])
            ->assertForbidden();
    }

    public function test_goals_validation_rejects_zero_minutes(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/dashboard/goals', ['response_time_minutes' => 0, 'cycle_time_days' => 18])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('response_time_minutes');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function efficiency(array $query = []): array
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/dashboard/metrics?'.http_build_query(['periodo' => 'este-mes', ...$query]))
            ->assertOk()
            ->json('efficiency');
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => $role === 'Owner' ? UserRole::ADMIN : UserRole::EMPLOYEE,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeConversation(): Conversation
    {
        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->channel->id,
            'contact_id' => $this->contact->id,
            'status' => 'open',
        ]);
    }

    private function message(Conversation $conversation, string $senderType, string $direction, string $at, bool $deleted = false): void
    {
        DB::table('messages')->insert([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_id' => $senderType === 'user' ? $this->owner->id : $this->contact->id,
            'direction' => $direction,
            'content' => 'hola',
            'created_at' => $at,
            'updated_at' => $at,
            'deleted_at' => $deleted ? $at : null,
        ]);
    }

    private function makeOpportunity(string $status, string $createdAt, ?string $closedAt = null): Opportunity
    {
        $stage = PipelineStage::where('tenant_id', $this->tenant->id)->orderBy('sort_order')->firstOrFail();

        $opportunity = new Opportunity([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'pipeline_stage_id' => $stage->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Venta',
            'status' => $status,
            'value' => 100,
            'closed_at' => $closedAt,
        ]);
        $opportunity->created_at = CarbonImmutable::parse($createdAt);
        $opportunity->save();

        return $opportunity;
    }

    private function makeTask(TaskStatus $status, string $deadline, User $assignee): void
    {
        Task::create([
            'tenant_id' => $this->tenant->id,
            'assigned_to' => $assignee->id,
            'name' => 'Seguimiento',
            'status' => $status,
            'deadline' => $deadline,
        ]);
    }
}
