<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\ScheduledMessageStatus;
use App\Enums\SenderType;
use App\Events\ScheduledMessageUpdated;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsMessengerConversation;
use Tests\TestCase;

class ScheduledMessageApiTest extends TestCase
{
    use BuildsMessengerConversation;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ScheduledMessageUpdated::class]);
    }

    public function test_schedules_a_text_message(): void
    {
        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);
        $when = now()->utc()->addHour()->startOfMinute();

        $this->postJson("/api/conversations/{$conversation->id}/scheduled-messages", [
            'type' => 'text',
            'content' => '  Te escribo mañana  ',
            'scheduled_for' => $when->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.content', 'Te escribo mañana')
            ->assertJsonPath('data.user_id', $user->id);

        $scheduled = ScheduledMessage::firstOrFail();
        $this->assertTrue($scheduled->scheduled_for->equalTo($when));
        $this->assertSame(0, Message::count());
        Event::assertDispatched(ScheduledMessageUpdated::class);
    }

    public function test_schedules_an_image_and_stores_the_file_now(): void
    {
        Storage::fake('public');
        [$user, $conversation, $tenant] = $this->createMessengerConversation();
        Sanctum::actingAs($user);

        $this->post("/api/conversations/{$conversation->id}/scheduled-messages", [
            'type' => 'image',
            'image' => UploadedFile::fake()->image('foto.jpg'),
            'content' => 'Mirá esto',
            'scheduled_for' => now()->utc()->addDay()->toIso8601String(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $scheduled = ScheduledMessage::firstOrFail();
        $this->assertStringStartsWith("messages/{$tenant->id}/", $scheduled->media_path);
        Storage::disk('public')->assertExists($scheduled->media_path);
    }

    public function test_rejects_past_dates(): void
    {
        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);

        $this->postJson("/api/conversations/{$conversation->id}/scheduled-messages", [
            'type' => 'text',
            'content' => 'Tarde',
            'scheduled_for' => now()->utc()->subMinute()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_for');
    }

    public function test_rejects_channels_without_scheduling(): void
    {
        [$user, $conversation] = $this->createMessengerConversation(type: ChannelType::MAIL);
        Sanctum::actingAs($user);

        $this->postJson("/api/conversations/{$conversation->id}/scheduled-messages", [
            'type' => 'text',
            'content' => 'Hola',
            'scheduled_for' => now()->utc()->addHour()->toIso8601String(),
        ])->assertUnprocessable();

        $this->assertSame(0, ScheduledMessage::count());
    }

    public function test_user_without_access_to_the_conversation_cannot_schedule(): void
    {
        [, $conversation, $tenant] = $this->createMessengerConversation();
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        $this->postJson("/api/conversations/{$conversation->id}/scheduled-messages", [
            'type' => 'text',
            'content' => 'Hola',
            'scheduled_for' => now()->utc()->addHour()->toIso8601String(),
        ])->assertForbidden();
    }

    public function test_index_lists_pending_and_window_expiry(): void
    {
        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);
        $pending = $this->makeScheduled($user, $conversation);
        $this->makeScheduled($user, $conversation, ScheduledMessageStatus::Sent);
        $this->makeScheduled($user, $conversation, ScheduledMessageStatus::Cancelled);
        $inbound = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'content' => 'hola',
            'message_type' => 'text',
            'direction' => MessageDirection::INBOUND,
            'sender_type' => SenderType::CONTACT,
            'sender_id' => $conversation->contact_id,
        ]);

        $response = $this->getJson("/api/conversations/{$conversation->id}/scheduled-messages")->assertOk();

        $this->assertSame([$pending->id], array_column($response->json('data'), 'id'));
        $this->assertTrue(
            $inbound->created_at->copy()->addHours(24)->equalTo($response->json('meta.window_expires_at'))
        );
    }

    public function test_reschedules_and_edits_content(): void
    {
        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);
        $scheduled = $this->makeScheduled($user, $conversation);
        $when = now()->utc()->addDays(2)->startOfMinute();

        $this->putJson("/api/scheduled-messages/{$scheduled->id}", [
            'content' => 'Texto nuevo',
            'scheduled_for' => $when->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.content', 'Texto nuevo');

        $this->assertTrue($scheduled->refresh()->scheduled_for->equalTo($when));
    }

    public function test_failed_message_can_be_rescheduled(): void
    {
        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);
        $scheduled = $this->makeScheduled($user, $conversation, ScheduledMessageStatus::Failed);

        $this->putJson("/api/scheduled-messages/{$scheduled->id}", [
            'scheduled_for' => now()->utc()->addHour()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.error_reason', null);
    }

    public function test_cannot_edit_a_message_already_being_sent(): void
    {
        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);
        $scheduled = $this->makeScheduled($user, $conversation, ScheduledMessageStatus::Sending);

        $this->putJson("/api/scheduled-messages/{$scheduled->id}", ['content' => 'Otro'])->assertUnprocessable();
        $this->deleteJson("/api/scheduled-messages/{$scheduled->id}")->assertUnprocessable();
        $this->assertSame(ScheduledMessageStatus::Sending, $scheduled->refresh()->status);
    }

    public function test_cancel_marks_cancelled_and_deletes_the_file(): void
    {
        Storage::fake('public');
        [$user, $conversation] = $this->createMessengerConversation();
        Sanctum::actingAs($user);
        Storage::disk('public')->put('messages/x/foto.jpg', 'img');
        $scheduled = $this->makeScheduled($user, $conversation, attributes: ['message_type' => 'image', 'media_path' => 'messages/x/foto.jpg']);

        $this->deleteJson("/api/scheduled-messages/{$scheduled->id}")->assertNoContent();

        $this->assertSame(ScheduledMessageStatus::Cancelled, $scheduled->refresh()->status);
        Storage::disk('public')->assertMissing('messages/x/foto.jpg');
    }

    public function test_another_agent_cannot_cancel_someone_elses_message(): void
    {
        [$owner, $conversation, $tenant] = $this->createMessengerConversation();
        $scheduled = $this->makeScheduled($owner, $conversation);
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');
        $conversation->update(['assigned_to' => $member->id]);
        Sanctum::actingAs($member);

        $this->deleteJson("/api/scheduled-messages/{$scheduled->id}")->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeScheduled(
        User $user,
        $conversation,
        ScheduledMessageStatus $status = ScheduledMessageStatus::Scheduled,
        array $attributes = [],
    ): ScheduledMessage {
        return ScheduledMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'message_type' => 'text',
            'content' => 'Hola',
            'scheduled_for' => now()->utc()->addHour(),
            'status' => $status,
            ...$attributes,
        ]);
    }
}
