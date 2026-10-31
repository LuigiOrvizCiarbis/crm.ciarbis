<?php

namespace Tests\Feature;

use App\Models\WhatsAppConfig;
use App\Services\WhatsAppMessagingLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppMessagingLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    private function config(): WhatsAppConfig
    {
        return WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0101',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => Crypt::encryptString('token'),
        ]);
    }

    private function fakeTier(?string $tier): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(
                $tier === null ? [] : ['whatsapp_business_manager_messaging_limit' => $tier],
            ),
        ]);
    }

    public function test_reads_tier_from_graph(): void
    {
        $this->fakeTier('TIER_1K');

        $result = app(WhatsAppMessagingLimitService::class)->forConfig($this->config());

        $this->assertTrue($result['known']);
        $this->assertSame('TIER_1K', $result['tier']);
        $this->assertSame(1000, $result['limit']);
        $this->assertFalse($result['unlimited']);
    }

    public function test_unlimited_tier_has_no_numeric_limit(): void
    {
        $this->fakeTier('TIER_UNLIMITED');

        $result = app(WhatsAppMessagingLimitService::class)->forConfig($this->config());

        $this->assertTrue($result['known']);
        $this->assertTrue($result['unlimited']);
        $this->assertNull($result['limit']);
    }

    /**
     * Un tier que Meta agregue más adelante no debe traducirse en un techo
     * inventado: se reporta como desconocido.
     */
    public function test_unrecognized_tier_is_reported_as_unknown(): void
    {
        $this->fakeTier('TIER_500K');

        $result = app(WhatsAppMessagingLimitService::class)->forConfig($this->config());

        $this->assertFalse($result['known']);
        $this->assertNull($result['limit']);
        $this->assertFalse($result['unlimited']);
    }

    public function test_graph_error_is_reported_as_unknown_not_as_no_limit(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(
                ['error' => ['code' => 190, 'message' => 'Invalid token']],
                401,
            ),
        ]);

        $result = app(WhatsAppMessagingLimitService::class)->forConfig($this->config());

        $this->assertFalse($result['known']);
        $this->assertFalse($result['unlimited']);
        $this->assertNull($result['limit']);
    }

    public function test_successful_read_is_cached(): void
    {
        $this->fakeTier('TIER_250');
        $config = $this->config();
        $service = app(WhatsAppMessagingLimitService::class);

        $service->forConfig($config);
        $service->forConfig($config);

        Http::assertSentCount(1);
    }

    /**
     * Cachear un fallo dejaría al usuario sin advertencia durante media hora
     * aunque Graph ya se haya recuperado.
     */
    public function test_failed_read_is_not_cached(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([], 500)]);
        $config = $this->config();
        $service = app(WhatsAppMessagingLimitService::class);

        $service->forConfig($config);
        $service->forConfig($config);

        Http::assertSentCount(2);
    }

    public function test_forget_clears_the_cached_value(): void
    {
        $this->fakeTier('TIER_250');
        $config = $this->config();
        $service = app(WhatsAppMessagingLimitService::class);

        $service->forConfig($config);
        $service->forget($config);
        $service->forConfig($config);

        Http::assertSentCount(2);
    }

    /**
     * Un token ilegible (no desencriptable) no debe disparar una llamada a
     * Graph que sabemos de antemano que va a fallar con 401.
     */
    public function test_config_without_usable_token_does_not_call_graph(): void
    {
        Http::fake();
        Cache::flush();

        $config = WhatsAppConfig::create([
            'phone_number_id' => 'phone-'.uniqid(),
            'display_phone_number' => '+54 9 223 555-0102',
            'waba_id' => 'waba-'.uniqid(),
            'bussines_token' => 'no-es-un-payload-cifrado',
        ]);

        $result = app(WhatsAppMessagingLimitService::class)->forConfig($config);

        $this->assertFalse($result['known']);
        Http::assertNothingSent();
    }
}
