<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\AiConfig;
use App\Models\AutomationRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAiImpactTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Contact $contact;

    private Channel $whatsapp;

    private Channel $instagram;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');

        $this->tenant = $this->createTenantWithRoles();
        $this->owner = $this->makeUser('Owner');
        $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Ana Martinez', 'source' => 'whatsapp']);
        $this->whatsapp = $this->channel(ChannelType::WHATSAPP, 'WhatsApp');
        $this->instagram = $this->channel(ChannelType::INSTAGRAM, 'Instagram');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_classifies_ai_conversations_by_outcome_and_times_ai_replies(): void
    {
        // Sin vendedor: la IA respondió a los 30 s y nadie más intervino.
        $solo = $this->conversation();
        $this->message($solo, 'contact', 'inbound', '2026-11-10 10:00:00');
        $this->message($solo, 'system', 'outbound', '2026-11-10 10:00:30');

        // Intervino un vendedor sin derivación; la IA respondió a los 10 s.
        $withHuman = $this->conversation();
        $this->message($withHuman, 'contact', 'inbound', '2026-11-10 11:00:00');
        $this->message($withHuman, 'system', 'outbound', '2026-11-10 11:00:10');
        $this->message($withHuman, 'contact', 'inbound', '2026-11-10 11:05:00');
        $this->message($withHuman, 'user', 'outbound', '2026-11-10 11:25:00');

        // Derivada: aunque después respondió un vendedor, cuenta como derivación.
        $handedOff = $this->conversation();
        $this->message($handedOff, 'contact', 'inbound', '2026-11-11 09:00:00');
        $this->message($handedOff, 'system', 'outbound', '2026-11-11 09:00:20');
        $this->handoff($handedOff, 'resolved', '2026-11-11 09:00:20', resolvedAt: '2026-11-11 09:10:20');
        $this->message($handedOff, 'user', 'outbound', '2026-11-11 09:10:20');

        // Sin respuesta de la IA (sólo una plantilla de automatización): no es de la IA.
        $templateOnly = $this->conversation();
        $this->message($templateOnly, 'contact', 'inbound', '2026-11-12 09:00:00');
        $this->message($templateOnly, 'system', 'outbound', '2026-11-12 09:00:05', type: 'template');
        $this->message($templateOnly, 'user', 'outbound', '2026-11-12 09:40:00');

        $ai = $this->ai();

        $this->assertSame(
            ['total' => 3, 'ai_only' => 1, 'with_human' => 1, 'handed_off' => 1],
            $ai['conversations'],
        );
        $this->assertSame(0, $ai['previous_conversations']['total']);
        $this->assertSame(3, $ai['response_time']['ai_samples']);
        // IA: 30 s, 10 s y 20 s.
        $this->assertSame(20, $ai['response_time']['ai_avg_seconds']);
        // Vendedores sólo en turnos que la IA no contestó: 20 min (11:05) y 40 min (sólo plantilla).
        // El turno de las 11:00 y el de la derivación los contestó la IA: no cargan al vendedor.
        $this->assertSame(1800, $ai['response_time']['human_avg_seconds']);
        $this->assertSame(10.0, (float) $ai['handoffs']['avg_minutes_to_attend']);
    }

    public function test_salesperson_filter_does_not_narrow_the_ai_block(): void
    {
        $seller = $this->makeUser('Member');
        $conversation = $this->conversation();
        $this->message($conversation, 'contact', 'inbound', '2026-11-10 10:00:00');
        $this->message($conversation, 'system', 'outbound', '2026-11-10 10:00:30');

        $this->assertSame(1, $this->ai(['owner' => $seller->id])['conversations']['total']);
    }

    public function test_handoffs_count_as_attended_from_the_first_human_reply(): void
    {
        // Pendiente sin respuesta posterior: la respuesta anterior a la derivación no la atiende.
        $older = $this->conversation();
        $this->message($older, 'user', 'outbound', '2026-10-01 08:00:00');
        $this->handoff($older, 'pending', '2026-10-02 08:00:00');
        $newer = $this->conversation();
        $this->handoff($newer, 'acknowledged', '2026-11-14 08:00:00', summary: 'Quiere coordinar una visita', acknowledgedAt: '2026-11-14 08:30:00');
        $done = $this->conversation();
        $this->handoff($done, 'resolved', '2026-11-13 08:00:00', resolvedAt: '2026-11-13 08:10:00');
        // Quedó "pendiente" aunque un vendedor contestó a los 20 min: está atendida.
        $answered = $this->conversation();
        $this->handoff($answered, 'pending', '2026-11-12 09:00:00');
        $this->message($answered, 'user', 'outbound', '2026-11-12 09:20:00');
        $this->handoff($this->conversation(), 'cancelled', '2026-11-13 09:00:00');

        $handoffs = $this->ai()['handoffs'];

        // En el período: tomada a los 30 min, resuelta a los 10 y respondida a los 20; la cancelada no cuenta.
        $this->assertSame(3, $handoffs['total']);
        $this->assertSame(20.0, (float) $handoffs['avg_minutes_to_attend']);
        $this->assertSame(2, $handoffs['waiting_count']);
        $this->assertSame([$older->id, $newer->id], array_column($handoffs['waiting'], 'conversation_id'));
        $this->assertNull($handoffs['waiting'][0]['motive']);
        $this->assertSame('Quiere coordinar una visita', $handoffs['waiting'][1]['motive']);
        $this->assertSame('Ana Martinez', $handoffs['waiting'][1]['contact_name']);
    }

    public function test_long_periods_only_count_automation_runs_still_retained(): void
    {
        $rule = $this->rule('Bienvenida');
        $this->automationRun($rule, 'succeeded', '2026-06-01 10:00:00', 'contact', $this->contact->id);
        $this->automationRun($rule, 'succeeded', '2026-10-01 10:00:00', 'contact', $this->contact->id);

        $year = $this->ai(['periodo' => 'este-ano'])['automations'];
        $this->assertSame(90, $year['limited_to_days']);
        $this->assertSame(1, $year['succeeded']);

        $this->assertNull($this->ai()['automations']['limited_to_days']);
    }

    public function test_counts_automation_runs_and_ranks_failing_rules(): void
    {
        $reminder = $this->rule('Recordatorio de pago');
        $welcome = $this->rule('Bienvenida');
        $conversation = $this->conversation();

        $this->automationRun($reminder, 'failed', '2026-11-10 10:00:00', 'conversation', $conversation->id);
        $this->automationRun($reminder, 'needs_review', '2026-11-11 10:00:00', 'contact', $this->contact->id);
        $this->automationRun($welcome, 'failed', '2026-11-12 10:00:00', 'contact', $this->contact->id);
        $this->automationRun($welcome, 'succeeded', '2026-11-12 11:00:00', 'contact', $this->contact->id);
        $this->automationRun($welcome, 'skipped', '2026-11-12 12:00:00', 'contact', $this->contact->id);
        // Fuera del período.
        $this->automationRun($welcome, 'failed', '2026-10-12 10:00:00', 'contact', $this->contact->id);

        $automations = $this->ai()['automations'];

        $this->assertSame(1, $automations['succeeded']);
        $this->assertSame(3, $automations['failed']);
        $this->assertSame(['Recordatorio de pago', 'Bienvenida'], array_column($automations['failing_rules'], 'name'));
        $this->assertSame([2, 1], array_column($automations['failing_rules'], 'failures'));

        // Con canal sólo cuentan las ejecuciones disparadas por conversaciones de ese canal.
        $byChannel = $this->ai(['canal' => $this->whatsapp->id])['automations'];
        $this->assertSame(['succeeded' => 0, 'failed' => 1], ['succeeded' => $byChannel['succeeded'], 'failed' => $byChannel['failed']]);
        $this->assertSame(0, $this->ai(['canal' => $this->instagram->id])['automations']['failed']);
    }

    public function test_reports_whether_ai_and_automations_are_set_up(): void
    {
        $this->assertFalse($this->ai()['ai_enabled']);
        $this->assertFalse($this->ai()['automations_active']);

        AiConfig::create(['tenant_id' => $this->tenant->id, 'provider' => 'openai', 'enabled' => true]);
        $this->rule('Bienvenida', 'active');

        $this->assertTrue($this->ai()['ai_enabled']);
        $this->assertTrue($this->ai()['automations_active']);
    }

    public function test_hidden_from_users_who_only_see_their_own_numbers(): void
    {
        $seller = $this->makeUser('Member');
        $seller->givePermissionTo('analytics.view');
        Sanctum::actingAs($seller);

        $this->getJson('/api/dashboard/metrics?periodo=este-mes')
            ->assertOk()
            ->assertJsonPath('ai', null);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function ai(array $query = []): array
    {
        Sanctum::actingAs($this->owner);

        return $this->getJson('/api/dashboard/metrics?'.http_build_query(['periodo' => 'este-mes', ...$query]))
            ->assertOk()
            ->json('ai');
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

    private function channel(ChannelType $type, string $name): Channel
    {
        return Channel::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->owner->id,
            'type' => $type,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function conversation(): Conversation
    {
        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $this->whatsapp->id,
            'contact_id' => $this->contact->id,
            'status' => 'open',
        ]);
    }

    private function message(Conversation $conversation, string $senderType, string $direction, string $at, string $type = 'text'): void
    {
        DB::table('messages')->insert([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_id' => $senderType === 'user' ? $this->owner->id : $this->contact->id,
            'direction' => $direction,
            'message_type' => $type,
            'content' => 'hola',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function handoff(
        Conversation $conversation,
        string $status,
        string $at,
        ?string $summary = null,
        ?string $acknowledgedAt = null,
        ?string $resolvedAt = null,
    ): void {
        DB::table('human_handoffs')->insert([
            'tenant_id' => $this->tenant->id,
            'conversation_id' => $conversation->id,
            'channel_id' => $conversation->channel_id,
            'status' => $status,
            'reason' => 'customer_requested_human',
            'summary' => $summary,
            'acknowledged_at' => $acknowledgedAt,
            'resolved_at' => $resolvedAt,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function rule(string $name, string $status = 'paused'): AutomationRule
    {
        return AutomationRule::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'status' => $status,
            'trigger_type' => 'contact_created',
            'trigger_config' => [],
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);
    }

    private function automationRun(AutomationRule $rule, string $status, string $finishedAt, string $subjectType, int $subjectId): void
    {
        DB::table('automation_runs')->insert([
            'tenant_id' => $this->tenant->id,
            'automation_rule_id' => $rule->id,
            'rule_version' => 1,
            'status' => $status,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'deduplication_key' => Str::random(40),
            'context' => '{}',
            'finished_at' => $finishedAt,
            'created_at' => $finishedAt,
            'updated_at' => $finishedAt,
        ]);
    }
}
