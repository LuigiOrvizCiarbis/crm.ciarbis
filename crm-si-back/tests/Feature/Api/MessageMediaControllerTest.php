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
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageMediaControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_download_media_of_their_own_tenant_message(): void
    {
        Storage::fake('public');
        [$owner, $message] = $this->messageWithAttachment();

        Sanctum::actingAs($owner);

        $response = $this->get("/api/messages/{$message->id}/media");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_download_query_param_forces_attachment_disposition(): void
    {
        Storage::fake('public');
        [$owner, $message] = $this->messageWithAttachment();

        Sanctum::actingAs($owner);

        $response = $this->get("/api/messages/{$message->id}/media?download=1");

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_message_from_another_tenant_is_not_accessible(): void
    {
        Storage::fake('public');
        [, $message] = $this->messageWithAttachment();

        $otherTenant = $this->createTenantWithRoles('Other tenant');
        $intruder = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => UserRole::ADMIN]);
        $intruder->assignRole('Owner');

        Sanctum::actingAs($intruder);

        // MessagePolicy::view resuelve por tenant_id explícito (Message no
        // tiene el global scope de tenant), igual que MessageTranslationController:
        // se documenta 403 y no 404, siguiendo authorize('view', $message).
        $this->get("/api/messages/{$message->id}/media")->assertForbidden();
    }

    public function test_returns_404_when_message_has_no_media(): void
    {
        [$owner, , $conversation] = $this->messageWithAttachment();

        $textMessage = Message::create([
            'tenant_id' => $owner->tenant_id,
            'conversation_id' => $conversation->id,
            'content' => 'Solo texto',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::INBOUND,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => null,
        ]);

        Sanctum::actingAs($owner);

        $this->get("/api/messages/{$textMessage->id}/media")->assertNotFound();
    }

    public function test_returns_404_when_file_missing_from_disk(): void
    {
        Storage::fake('public');
        [$owner, $message] = $this->messageWithAttachment();

        Storage::disk('public')->delete('messages/'.$owner->tenant_id.'/photo.png');

        Sanctum::actingAs($owner);

        $this->get("/api/messages/{$message->id}/media")->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Message, 2: Conversation}
     */
    private function messageWithAttachment(): array
    {
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

        $path = 'messages/'.$tenant->id.'/photo.png';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('photo.png')->get());

        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'content' => '',
            'message_type' => MessageType::Image,
            'direction' => MessageDirection::INBOUND,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $contact->id,
            'media_url' => '/storage/'.$path,
            'media_mime_type' => 'image/png',
            'media_filename' => 'photo.png',
        ]);

        return [$owner, $message, $conversation];
    }
}
