<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardRevenueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Contact $contact;

    /** @var list<PipelineStage> */
    private array $stages;

    private int $invoiceNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');

        $this->tenant = $this->createTenantWithRoles();
        $this->owner = $this->makeUser('Owner');
        $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Ana', 'source' => 'manual']);
        $this->stages = PipelineStage::withoutGlobalScopes()
            ->where('pipeline_id', Pipeline::defaultFor($this->tenant->id)->id)
            ->orderBy('sort_order')
            ->take(3)
            ->get()
            ->all();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_won_counts_by_closed_at_not_by_activity(): void
    {
        // Vieja, sin actividad en el período, pero ganada adentro: cuenta.
        $this->opportunity('won', 400, createdAt: '2026-01-10', closedAt: '2026-11-10', lastActivityAt: '2026-01-10');
        // Con actividad reciente pero ganada antes del período: no cuenta.
        $this->opportunity('won', 900, createdAt: '2026-11-05', closedAt: '2026-10-20', lastActivityAt: '2026-11-05');

        $response = $this->metrics();

        $this->assertSame(1, $response->json('kpis.won_count'));
        $this->assertEquals(400, $response->json('kpis.won_value'));
        $this->assertSame(1, $response->json('revenue.won_count'));
        $this->assertEquals(400, $response->json('revenue.won_value'));
        $this->assertSame(1, $response->json('previous.won_count'));
        $this->assertEquals(900, $response->json('previous.won_value'));
    }

    public function test_avg_ticket_replaces_avg_roi(): void
    {
        $this->opportunity('won', 100, closedAt: '2026-11-03');
        $this->opportunity('won', 300, closedAt: '2026-11-04');
        $this->opportunity('lost', 5000, closedAt: '2026-11-04');

        $response = $this->metrics();

        $this->assertEquals(200, $response->json('kpis.avg_ticket'));
        $this->assertEquals(0, $response->json('previous.avg_ticket'));
        $this->assertEquals(100, $response->json('trends.avg_ticket'));
        $this->assertArrayNotHasKey('avg_roi', $response->json('kpis'));
        $this->assertArrayNotHasKey('avg_roi', $response->json('previous'));
        $this->assertArrayNotHasKey('avg_roi', $response->json('trends'));

        $this->assertEquals(0, $this->metrics(['periodo' => 'hoy'])->json('kpis.avg_ticket'));
    }

    public function test_projection_weighs_open_opportunities_by_stage_probability(): void
    {
        [$likely, $unknown, $empty] = $this->stages;
        $likely->update(['win_probability' => 50]);
        $empty->update(['win_probability' => 20]);

        // Las abiertas son una foto: no importa cuándo se crearon.
        $this->opportunity('open', 1000, stage: $likely, createdAt: '2020-01-01', lastActivityAt: '2020-01-01');
        $this->opportunity('open', 200, stage: $likely);
        $this->opportunity('open', 500, stage: $unknown);
        $this->opportunity('won', 100, stage: $unknown, closedAt: '2026-11-02');

        $revenue = $this->metrics()->json('revenue');

        $this->assertEquals(600, $revenue['open_weighted_value']);
        $this->assertEquals(700, $revenue['projected_value']);
        $this->assertSame(1, $revenue['stages_without_probability']);
    }

    public function test_projection_is_null_without_probabilities(): void
    {
        $this->opportunity('open', 500, stage: $this->stages[0]);
        $this->opportunity('won', 100, closedAt: '2026-11-02');

        $revenue = $this->metrics()->json('revenue');

        $this->assertNull($revenue['projected_value']);
        $this->assertEquals(0, $revenue['open_weighted_value']);
        $this->assertSame(1, $revenue['stages_without_probability']);
    }

    public function test_goal_scales_with_the_period(): void
    {
        $this->assertNull($this->metrics()->json('revenue.goal'));

        $this->tenant->update(['revenue_goal_monthly' => 1000]);

        $this->assertEquals(1000, $this->metrics(['periodo' => 'este-mes'])->json('revenue.goal'));
        $this->assertEquals(3000, $this->metrics(['periodo' => 'este-trimestre'])->json('revenue.goal'));
        $this->assertEquals(12000, $this->metrics(['periodo' => 'este-ano'])->json('revenue.goal'));
        $this->assertNull($this->metrics(['periodo' => 'esta-semana'])->json('revenue.goal'));
        $this->assertNull($this->metrics(['periodo' => 'hoy'])->json('revenue.goal'));
    }

    public function test_by_channel_groups_closed_opportunities_and_ignores_the_channel_filter(): void
    {
        $whatsapp = $this->channel(ChannelType::WHATSAPP, 'WhatsApp');
        $instagram = $this->channel(ChannelType::INSTAGRAM, 'Instagram');
        $viaWhatsapp = $this->conversation($whatsapp);
        $viaInstagram = $this->conversation($instagram);

        $this->opportunity('won', 300, closedAt: '2026-11-02', conversation: $viaWhatsapp);
        $this->opportunity('won', 200, closedAt: '2026-11-03', conversation: $viaWhatsapp);
        $this->opportunity('lost', 50, closedAt: '2026-11-04', conversation: $viaWhatsapp);
        $this->opportunity('open', 999, conversation: $viaWhatsapp);
        $this->opportunity('won', 777, closedAt: '2026-10-01', conversation: $viaWhatsapp);
        $this->opportunity('won', 1000, closedAt: '2026-11-05', conversation: $viaInstagram);
        $this->opportunity('lost', 80, closedAt: '2026-11-06');

        $expected = [
            ['channel_id' => $instagram->id, 'channel_name' => 'Instagram', 'channel_type' => ChannelType::INSTAGRAM->value, 'won_value' => 1000, 'won_count' => 1, 'lost_count' => 0],
            ['channel_id' => $whatsapp->id, 'channel_name' => 'WhatsApp', 'channel_type' => ChannelType::WHATSAPP->value, 'won_value' => 500, 'won_count' => 2, 'lost_count' => 1],
            ['channel_id' => null, 'channel_name' => null, 'channel_type' => null, 'won_value' => 0, 'won_count' => 0, 'lost_count' => 1],
        ];

        $this->assertEquals($expected, $this->metrics()->json('revenue.by_channel'));

        $filtered = $this->metrics(['canal' => $whatsapp->id]);
        $this->assertEquals($expected, $filtered->json('revenue.by_channel'));
        $this->assertSame(2, $filtered->json('kpis.won_count'));
    }

    public function test_collections_split_collected_outstanding_and_overdue(): void
    {
        $centro = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Centro']);
        $norte = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Norte']);
        $centroContact = $this->branchContact($centro);
        $norteContact = $this->branchContact($norte);

        $overdue = $this->invoice($centroContact, 10000, dueOn: '2026-11-01');
        $this->payment($overdue, 3000, '2026-11-05');
        $this->payment($overdue, 2000, '2026-11-06', reversed: true);

        $pending = $this->invoice($centroContact, 5000, dueOn: '2026-12-01');
        $this->payment($pending, 1000, '2026-10-20');

        $this->invoice($norteContact, 4000, override: 'overdue');
        $this->invoice($centroContact, 9999, status: 'draft');
        $this->payment($this->invoice($centroContact, 8000, status: 'void'), 8000, '2026-11-07');
        $this->invoice($centroContact, 100, currency: 'USD');

        $this->assertSame([
            'currency' => 'ARS',
            'other_currencies' => true,
            'collected_cents' => 3000,
            'outstanding_cents' => 15000,
            'overdue_cents' => 11000,
            'overdue_count' => 2,
        ], $this->metrics()->json('revenue.collections'));

        $this->assertSame([
            'currency' => 'ARS',
            'other_currencies' => false,
            'collected_cents' => 0,
            'outstanding_cents' => 4000,
            'overdue_cents' => 4000,
            'overdue_count' => 1,
        ], $this->metrics(['sucursal' => $norte->id])->json('revenue.collections'));

        $this->assertSame(
            [true, 3000, 11000, 7000, 1],
            array_values(array_intersect_key(
                $this->metrics(['sucursal' => $centro->id])->json('revenue.collections'),
                array_flip(['collected_cents', 'outstanding_cents', 'overdue_cents', 'overdue_count', 'other_currencies']),
            )),
        );
    }

    public function test_main_currency_is_the_one_with_more_activity(): void
    {
        $this->invoice($this->contact, 1000);
        $this->invoice($this->contact, 500000, currency: 'USD');

        $collections = $this->metrics()->json('revenue.collections');

        $this->assertSame('USD', $collections['currency']);
        $this->assertTrue($collections['other_currencies']);
        $this->assertSame(500000, $collections['outstanding_cents']);
    }

    public function test_collections_are_null_without_invoices_or_permission(): void
    {
        $this->invoice($this->contact, 1000, status: 'draft');
        $this->assertNull($this->metrics()->json('revenue.collections'));

        $this->invoice($this->contact, 1000);
        $this->assertNotNull($this->metrics()->json('revenue.collections'));

        Sanctum::actingAs($this->makeUser('Member'));
        $this->getJson('/api/dashboard/metrics?periodo=este-mes')
            ->assertOk()
            ->assertJsonPath('revenue.collections', null);
    }

    public function test_revenue_goal_is_stored_and_validated(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson('/api/dashboard/goals', ['revenue_monthly' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('revenue_monthly');

        $this->putJson('/api/dashboard/goals', ['revenue_monthly' => 1500000.5])
            ->assertOk()
            ->assertJsonPath('data.revenue_monthly', 1500000.5)
            ->assertJsonPath('data.response_time_minutes', null);

        $this->getJson('/api/dashboard/goals')->assertJsonPath('data.revenue_monthly', 1500000.5);
        $this->assertEquals(4500001.5, $this->metrics(['periodo' => 'este-trimestre'])->json('revenue.goal'));

        $this->putJson('/api/dashboard/goals', ['revenue_monthly' => null])
            ->assertOk()
            ->assertJsonPath('data.revenue_monthly', null);

        Sanctum::actingAs($this->makeUser('Member'));
        $this->putJson('/api/dashboard/goals', ['revenue_monthly' => 1000])->assertForbidden();
    }

    public function test_stage_win_probability_is_stored_and_validated(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/pipeline-stages', ['name' => 'Propuesta', 'win_probability' => 60])
            ->assertCreated()
            ->assertJsonPath('win_probability', 60);

        $stage = $this->stages[0];

        $this->putJson("/api/pipeline-stages/{$stage->id}", ['win_probability' => 101])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('win_probability');

        $this->putJson("/api/pipeline-stages/{$stage->id}", ['win_probability' => 35])
            ->assertOk()
            ->assertJsonPath('win_probability', 35);

        $listed = collect($this->getJson('/api/pipeline-stages')->assertOk()->json())->firstWhere('id', $stage->id);
        $this->assertSame(35, $listed['win_probability']);

        $this->putJson("/api/pipeline-stages/{$stage->id}", ['win_probability' => null])
            ->assertOk()
            ->assertJsonPath('win_probability', null);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function metrics(array $query = []): TestResponse
    {
        // La relación queda cacheada entre requests: se relee el tenant actualizado.
        Sanctum::actingAs($this->owner->unsetRelation('tenant'));

        return $this->getJson('/api/dashboard/metrics?'.http_build_query(['periodo' => 'este-mes', ...$query]))
            ->assertOk();
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

    private function opportunity(
        string $status,
        float $value,
        ?PipelineStage $stage = null,
        ?string $createdAt = null,
        ?string $closedAt = null,
        ?string $lastActivityAt = null,
        ?Conversation $conversation = null,
    ): Opportunity {
        $opportunity = new Opportunity([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $this->contact->id,
            'conversation_id' => $conversation?->id,
            'pipeline_stage_id' => ($stage ?? $this->stages[0])->id,
            'assigned_to' => $this->owner->id,
            'title' => 'Venta',
            'status' => $status,
            'source_type' => 'manual',
            'value' => $value,
        ]);
        $opportunity->closed_at = $closedAt;
        $opportunity->last_activity_at = $lastActivityAt;
        $opportunity->created_at = CarbonImmutable::parse($createdAt ?? now());
        $opportunity->save();

        return $opportunity;
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

    private function conversation(Channel $channel): Conversation
    {
        return Conversation::create([
            'tenant_id' => $this->tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $this->contact->id,
            'status' => 'open',
        ]);
    }

    private function branchContact(Branch $branch): Contact
    {
        return Contact::create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'name' => 'Cliente '.$branch->name,
            'source' => 'manual',
        ]);
    }

    private function invoice(
        Contact $contact,
        int $amountCents,
        string $status = 'issued',
        string $currency = 'ARS',
        ?string $dueOn = null,
        ?string $override = null,
    ): Invoice {
        return Invoice::create([
            'tenant_id' => $this->tenant->id,
            'contact_id' => $contact->id,
            'number' => 'INV-'.++$this->invoiceNumber,
            'concept' => 'Servicio',
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'status' => $status,
            'due_on' => $dueOn,
            'collection_status_override' => $override,
        ]);
    }

    private function payment(Invoice $invoice, int $amountCents, string $paidOn, bool $reversed = false): void
    {
        InvoicePayment::create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'amount_cents' => $amountCents,
            'paid_on' => $paidOn,
            'reversed_at' => $reversed ? now() : null,
        ]);
    }
}
