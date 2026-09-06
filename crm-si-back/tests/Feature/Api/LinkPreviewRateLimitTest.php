<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LinkPreviewRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_preview_retry_is_rate_limited_per_user(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        // Bajar el límite a 3/min en vez de mandar 21 requests reales.
        RateLimiter::for('link-preview', fn ($request) => Limit::perMinute(3)->by($request->user()?->id));

        $tenant = $this->createTenantWithRoles();
        $owner = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $owner->assignRole('Owner');

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $owner->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Canal',
            'status' => 'active',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Contacto',
            'phone' => '+5493400000000',
            'source' => 'whatsapp',
        ]);

        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'content' => 'https://example.com/a',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
        ]);

        Sanctum::actingAs($owner);

        $first = $this->postJson("/api/messages/{$message->id}/link-preview")->assertOk();
        $first->assertJsonStructure(['data' => ['id', 'url', 'title', 'description', 'site_name', 'image_url', 'status', 'fetched_at', 'failure_reason']]);
        $first->assertJsonPath('data.status', 'ok');

        $this->postJson("/api/messages/{$message->id}/link-preview")->assertOk();
        $this->postJson("/api/messages/{$message->id}/link-preview")->assertOk();

        $this->postJson("/api/messages/{$message->id}/link-preview")->assertStatus(429);
    }
}
