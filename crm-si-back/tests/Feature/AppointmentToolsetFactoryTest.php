<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Scheduling\AppointmentToolsetFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

class AppointmentToolsetFactoryTest extends TestCase
{
    use AiSchedulingFixtures, RefreshDatabase;

    private Tenant $tenant;

    private User $seller;

    private User $fallback;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->tenant, $this->seller] = $this->makeTenantWithOwner();
        $this->fallback = $this->makeUser($this->tenant);
    }

    public function test_uses_assigned_seller_when_connected(): void
    {
        $this->connectGoogle($this->seller);
        $this->connectGoogle($this->fallback);
        $this->makeSchedulingConfig($this->tenant, ['default_user_id' => $this->fallback->id]);

        $toolset = $this->factory()->forConversation($this->makeConversation($this->tenant, $this->seller, $this->seller->id));

        $this->assertSame($this->seller->id, $toolset?->owner()->id);
    }

    public function test_falls_back_to_default_user_when_seller_not_connected(): void
    {
        $this->connectGoogle($this->seller, 'needs_reauth');
        $this->connectGoogle($this->fallback);
        $this->makeSchedulingConfig($this->tenant, ['default_user_id' => $this->fallback->id]);

        $toolset = $this->factory()->forConversation($this->makeConversation($this->tenant, $this->seller, $this->seller->id));

        $this->assertSame($this->fallback->id, $toolset?->owner()->id);
    }

    public function test_no_tools_without_connected_calendar_or_enabled_config(): void
    {
        $conversation = $this->makeConversation($this->tenant, $this->seller, $this->seller->id);
        $config = $this->makeSchedulingConfig($this->tenant, ['default_user_id' => $this->fallback->id]);

        $this->assertNull($this->factory()->forConversation($conversation));

        $this->connectGoogle($this->seller);
        $config->update(['enabled' => false]);

        $this->assertNull($this->factory()->forConversation($conversation));
    }


    private function factory(): AppointmentToolsetFactory
    {
        return app(AppointmentToolsetFactory::class);
    }
}
