<?php

namespace Tests\Feature;

use App\Enums\AiProvider as AiProviderEnum;
use App\Models\AiConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AiReplyService;
use App\Services\Scheduling\AppointmentToolsetFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use ReflectionMethod;
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

    /**
     * Sin agenda el system prompt no cambia: la regla anti-historial sigue al final.
     */
    public function test_system_prompt_adds_agenda_section_only_with_toolset(): void
    {
        $this->connectGoogle($this->seller);
        $this->makeSchedulingConfig($this->tenant);
        $conversation = $this->makeConversation($this->tenant, $this->seller, $this->seller->id);
        $aiConfig = AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => AiProviderEnum::CLAUDE,
            'enabled' => true,
            'system_prompt' => 'Sos el asistente de Acme.',
        ]);
        $method = new ReflectionMethod(AiReplyService::class, 'systemPrompt');
        $service = app(AiReplyService::class);

        $without = $method->invoke($service, $aiConfig, $this->tenant->id, [], null);
        $with = $method->invoke($service, $aiConfig, $this->tenant->id, [], $this->factory()->forConversation($conversation));

        $this->assertSame("Sos el asistente de Acme.\n\n".AiReplyService::HISTORY_OVERRIDE_RULE, $without);
        $this->assertStringContainsString('## Agenda de turnos', $with);
        $this->assertStringContainsString('quedan pendientes hasta que un asesor los confirme', $with);
        $this->assertStringEndsWith(AiReplyService::HISTORY_OVERRIDE_RULE, $with);
    }

    private function factory(): AppointmentToolsetFactory
    {
        return app(AppointmentToolsetFactory::class);
    }
}
