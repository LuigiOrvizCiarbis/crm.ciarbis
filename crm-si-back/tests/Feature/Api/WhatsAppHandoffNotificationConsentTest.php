<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Jobs\SendHumanHandoffNotificationJob;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\HumanHandoff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhatsAppHandoffNotificationConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_subscribe_without_a_verification_message_and_revoke_consent(): void
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user);
        Http::fake();

        $this->postJson('/api/profile/whatsapp-notifications', [
            'phone' => '+1 202 555 0123',
            'consent' => true,
        ])->assertOk()
            ->assertJsonPath('data.whatsapp_notification.phone', '+1 202 555 0123')
            ->assertJsonPath('data.whatsapp_notification.opted_in_at', fn ($value) => $value !== null);

        $this->assertSame('12025550123', $user->fresh()->whatsapp_notification_phone_normalized);
        $this->assertNull($user->fresh()->whatsapp_notification_verified_at);
        Http::assertNothingSent();

        $this->deleteJson('/api/profile/whatsapp-notifications')
            ->assertOk()
            ->assertJsonPath('data.whatsapp_notification.phone', null)
            ->assertJsonPath('data.whatsapp_notification.opted_in_at', null);

        $this->assertNull($user->fresh()->whatsapp_notification_phone_normalized);
    }

    public function test_user_cannot_subscribe_without_explicit_consent_or_valid_phone(): void
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/whatsapp-notifications', [
            'phone' => '+1 202 555 0123',
            'consent' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('consent');

        $this->postJson('/api/profile/whatsapp-notifications', [
            'phone' => '123',
            'consent' => true,
        ])->assertUnprocessable();

        $this->assertNull($user->fresh()->whatsapp_notification_opted_in_at);
    }

    public function test_handoff_alert_uses_consented_number_without_otp_verification(): void
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/profile/whatsapp-notifications', [
            'phone' => '+1 202 555 0123',
            'consent' => true,
        ])->assertOk();

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Ventas',
            'status' => 'active',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'kind' => 'direct',
            'status' => 'open',
        ]);
        $handoff = HumanHandoff::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'conversation_id' => $conversation->id,
            'assigned_to' => $user->id,
            'status' => 'pending',
            'summary' => 'El cliente pidió hablar con una persona.',
        ]);

        config()->set('services.si_crm_alerts.phone_number_id', 'phone-alerts');
        config()->set('services.si_crm_alerts.access_token', 'token-test');
        Http::fake(['https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.alert1']]], 200)]);

        (new SendHumanHandoffNotificationJob($handoff->id))->handle();

        Http::assertSent(fn ($request) => $request->data()['to'] === '12025550123'
            && $request->data()['type'] === 'template');
        $this->assertSame('sent', $handoff->notifications()->firstOrFail()->status);
        $this->assertNull($user->fresh()->whatsapp_notification_verified_at);
    }
}
