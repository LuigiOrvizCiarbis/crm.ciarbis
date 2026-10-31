<?php

namespace Tests\Feature\Api;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fija la forma del payload de sesión (login y GET /api/user) antes de tocar
 * la serialización de User. AuthGuard.tsx lee `user.tenant.plan.key` anidado
 * para decidir si el trial venció (lib/trial.ts) — si un UserResource futuro
 * aplana o pierde ese anidamiento, un tenant de pago con trial_ends_at vencido
 * queda expulsado a /trial-expired sin que ningún test de PHP lo detecte.
 */
class UserSessionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_payload_keeps_nested_tenant_plan_shape(): void
    {
        [$tenant, $user] = $this->makeOwnerTenantOnPlan('pro');

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $response->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.tenant.id', $tenant->id)
            ->assertJsonPath('user.tenant.plan.key', 'pro')
            ->assertJsonPath('user.tenant.trial_ends_at', fn ($value) => $value !== null)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'tenant' => ['id', 'name', 'plan' => ['id', 'key', 'name']]],
                'role',
                'permissions',
                'email_verified',
            ]);
    }

    public function test_me_payload_keeps_nested_tenant_plan_shape(): void
    {
        [$tenant, $user] = $this->makeOwnerTenantOnPlan('enterprise');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user')->assertOk();

        $response->assertJsonPath('user.tenant.plan.key', 'enterprise')
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'tenant' => ['id', 'plan' => ['key']]],
                'role',
                'permissions',
            ]);
    }

    /**
     * Prueba de regresión directa del riesgo: un tenant de pago con trial
     * vencido no debe leerse como "trial expirado" a partir del payload de
     * sesión, porque lib/trial.ts sólo trata como expirado al plan 'free'.
     */
    public function test_paid_tenant_with_past_trial_end_is_not_flagged_as_free(): void
    {
        [$tenant, $user] = $this->makeOwnerTenantOnPlan('pro', now()->subDays(10));

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertNotSame('free', $response->json('user.tenant.plan.key'));
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function makeOwnerTenantOnPlan(string $planKey, ?\DateTimeInterface $trialEndsAt = null): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);

        $plan = Plan::where('key', $planKey)->firstOrFail();

        $tenant = Tenant::create([
            'name' => 'Acme',
            'plan_id' => $plan->id,
            'trial_ends_at' => $trialEndsAt ?? now()->addDays(14),
        ]);

        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'password' => Hash::make('password'),
        ]);

        $registrar->setPermissionsTeamId($tenant->id);
        $user->syncRoles(['Owner']);

        return [$tenant, $user];
    }
}
