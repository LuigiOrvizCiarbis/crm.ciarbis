<?php

namespace Tests\Feature\Api;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Jobs\SendLangfuseScoreJob;
use App\Models\AiMessageFeedback;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageAiFeedbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Message}
     */
    private function createAiMessage(?string $traceId = null): array
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $user->assignRole('Owner');

        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ChannelType::WHATSAPP,
            'name' => 'Main channel',
            'status' => 'active',
        ]);
        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Jane Doe',
            'phone' => '5492235112208',
            'source' => 'whatsapp',
        ]);
        $conversation = Conversation::create([
            'tenant_id' => $tenant->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $message = Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::SYSTEM,
            'content' => 'Hola, sí tenemos stock.',
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            'ai_trace_id' => $traceId,
            'ai_observation_id' => $traceId ? str_repeat('b', 16) : null,
        ]);

        return [$user, $message];
    }

    public function test_operator_rates_ai_message_and_score_is_queued(): void
    {
        Queue::fake();
        [$user, $message] = $this->createAiMessage(str_repeat('a', 32));
        Sanctum::actingAs($user);

        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => -1])->assertNoContent();

        $this->assertDatabaseHas('ai_message_feedback', [
            'user_id' => $user->id,
            'message_id' => $message->id,
            'tenant_id' => $message->tenant_id,
            'rating' => -1,
        ]);
        Queue::assertPushed(SendLangfuseScoreJob::class, fn (SendLangfuseScoreJob $job) => $job->traceId === str_repeat('a', 32)
            && $job->observationId === str_repeat('b', 16)
            && $job->feedback->rating === -1
            && $job->scoreId() === 'crm-'.config('langfuse.environment').'-ai-msg-'.$message->id.'-u'.$user->id);
    }

    public function test_changing_vote_updates_the_same_row(): void
    {
        Queue::fake();
        [$user, $message] = $this->createAiMessage(str_repeat('a', 32));
        Sanctum::actingAs($user);

        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => -1])->assertNoContent();
        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => 1])->assertNoContent();

        $this->assertSame(1, AiMessageFeedback::query()->count());
        $this->assertSame(AiMessageFeedback::UP, AiMessageFeedback::query()->first()->rating);
    }

    public function test_delayed_score_job_sends_the_current_vote(): void
    {
        Queue::fake();
        [$user, $message] = $this->createAiMessage(str_repeat('a', 32));
        Sanctum::actingAs($user);
        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => -1])->assertNoContent();
        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => 1])->assertNoContent();

        config(['langfuse.enabled' => true, 'langfuse.host' => 'https://cloud.langfuse.com', 'langfuse.public_key' => 'pk', 'langfuse.secret_key' => 'sk']);
        Http::fake(['cloud.langfuse.com/*' => Http::response([], 200)]);

        // El job del 👎 corre recién ahora (reintento atrasado): se recarga del queue como en el worker.
        $staleJob = Queue::pushed(SendLangfuseScoreJob::class)->first();
        unserialize(serialize($staleJob))->handle();

        Http::assertSent(fn (Request $request) => $request->data()[0]['value'] === 1);
    }

    public function test_message_without_trace_is_rejected(): void
    {
        Queue::fake();
        [$user, $message] = $this->createAiMessage();
        Sanctum::actingAs($user);

        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => 1])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_user_from_other_tenant_is_forbidden(): void
    {
        Queue::fake();
        [, $message] = $this->createAiMessage(str_repeat('a', 32));
        $other = $this->createTenantWithRoles('Otra');
        $outsider = User::factory()->create(['tenant_id' => $other->id, 'role' => UserRole::ADMIN]);
        $outsider->assignRole('Owner');
        Sanctum::actingAs($outsider);

        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => 1])->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_same_tenant_user_who_cannot_view_the_conversation_is_forbidden(): void
    {
        Queue::fake();
        [, $message] = $this->createAiMessage(str_repeat('a', 32));
        // Member solo ve conversaciones asignadas, y esta no tiene asignado.
        $member = User::factory()->create(['tenant_id' => $message->tenant_id]);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => 1])->assertForbidden();

        $this->assertDatabaseCount('ai_message_feedback', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_rating_is_rejected(): void
    {
        Queue::fake();
        [$user, $message] = $this->createAiMessage(str_repeat('a', 32));
        Sanctum::actingAs($user);

        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rating');
    }

    public function test_messages_endpoint_includes_current_user_feedback(): void
    {
        Queue::fake();
        [$user, $message] = $this->createAiMessage(str_repeat('a', 32));
        Sanctum::actingAs($user);
        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => -1])->assertNoContent();

        $item = $this->getJson("/api/conversations/{$message->conversation_id}/messages")
            ->assertOk()
            ->json('data.0');

        $this->assertSame(-1, $item['ai_feedback'][0]['rating']);
        $this->assertTrue($item['ai_traced']);
        $this->assertArrayNotHasKey('ai_trace_id', $item);
    }

    public function test_conversation_detail_includes_current_user_feedback(): void
    {
        Queue::fake();
        [$user, $message] = $this->createAiMessage(str_repeat('a', 32));
        Sanctum::actingAs($user);
        $this->putJson("/api/messages/{$message->id}/ai-feedback", ['rating' => 1])->assertNoContent();

        $item = $this->getJson("/api/conversations/{$message->conversation_id}")
            ->assertOk()
            ->json('data.messages.0');

        $this->assertSame(1, $item['ai_feedback'][0]['rating']);
    }
}
