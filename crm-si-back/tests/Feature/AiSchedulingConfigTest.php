<?php

namespace Tests\Feature;

use App\Models\AiSchedulingConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

class AiSchedulingConfigTest extends TestCase
{
    use AiSchedulingFixtures, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->admin] = $this->makeTenantWithOwner();
        Sanctum::actingAs($this->admin);
    }

    public function test_show_returns_defaults_and_only_connected_users_of_tenant(): void
    {
        $this->connectGoogle($this->admin);
        $this->connectGoogle($this->makeUser($this->tenant), 'needs_reauth');
        $this->makeUser($this->tenant);
        [, $foreignUser] = $this->makeTenantWithOwner();
        $this->connectGoogle($foreignUser);

        $response = $this->getJson('/api/ai-config/scheduling');

        $response->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.mode', 'approval')
            ->assertJsonPath('data.working_hours.mon', [['start' => '09:00', 'end' => '18:00']])
            ->assertJsonPath('data.working_hours.sat', [])
            ->assertJsonPath('data.calendar_users', [[
                'id' => $this->admin->id,
                'name' => $this->admin->name,
                'email' => $this->admin->email,
            ]]);
    }

    public function test_update_persists_and_fills_missing_days(): void
    {
        $payload = $this->validPayload([
            'mode' => 'criteria',
            'criteria' => 'Directo si ya dio presupuesto',
            'default_user_id' => $this->admin->id,
            'working_hours' => ['tue' => [['start' => '10:00', 'end' => '13:00'], ['start' => '15:00', 'end' => '19:00']]],
        ]);

        $this->putJson('/api/ai-config/scheduling', $payload)
            ->assertOk()
            ->assertJsonPath('data.mode', 'criteria')
            ->assertJsonPath('data.default_user_id', $this->admin->id);

        $config = AiSchedulingConfig::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole();
        $this->assertTrue($config->enabled);
        $this->assertSame('Directo si ya dio presupuesto', $config->criteria);
        $this->assertSame([], $config->working_hours['mon']);
        $this->assertCount(2, $config->working_hours['tue']);
    }

    public function test_criteria_is_required_in_criteria_mode(): void
    {
        $this->putJson('/api/ai-config/scheduling', $this->validPayload(['mode' => 'criteria', 'criteria' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['criteria']);
    }

    public function test_inverted_or_unknown_working_hours_are_rejected(): void
    {
        $this->putJson('/api/ai-config/scheduling', $this->validPayload(['working_hours' => ['mon' => [['start' => '18:00', 'end' => '09:00']]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['working_hours']);

        $this->putJson('/api/ai-config/scheduling', $this->validPayload(['working_hours' => ['lunes' => [['start' => '09:00', 'end' => '18:00']]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['working_hours']);
    }

    public function test_default_user_must_belong_to_tenant(): void
    {
        [, $foreignUser] = $this->makeTenantWithOwner();

        $this->putJson('/api/ai-config/scheduling', $this->validPayload(['default_user_id' => $foreignUser->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['default_user_id']);
    }

    public function test_member_without_manage_permission_cannot_update(): void
    {
        $member = $this->makeUser($this->tenant);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        $this->putJson('/api/ai-config/scheduling', $this->validPayload())->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'enabled' => true,
            'mode' => 'approval',
            'criteria' => null,
            'default_user_id' => null,
            'timezone' => self::TZ,
            'meeting_duration_minutes' => 30,
            'slot_interval_minutes' => 30,
            'min_notice_hours' => 2,
            'max_days_ahead' => 14,
            'working_hours' => AiSchedulingConfig::DEFAULT_WORKING_HOURS,
            ...$overrides,
        ];
    }
}
