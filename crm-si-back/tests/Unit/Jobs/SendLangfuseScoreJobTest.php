<?php

namespace Tests\Unit\Jobs;

use App\Jobs\SendLangfuseScoreJob;
use App\Models\AiMessageFeedback;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendLangfuseScoreJobTest extends TestCase
{
    private function job(?string $observationId = 'bbbbbbbbbbbbbbbb'): SendLangfuseScoreJob
    {
        $feedback = new AiMessageFeedback(['user_id' => 2, 'message_id' => 1, 'rating' => AiMessageFeedback::DOWN]);
        // Eloquent guarda la hora sin zona y la relee en la del app.
        $feedback->created_at = Carbon::parse('2026-11-28T10:00:00Z')->setTimezone(config('app.timezone'));

        return new SendLangfuseScoreJob($feedback, 'ai-msg-1', str_repeat('a', 32), $observationId);
    }

    private function enableLangfuse(int $status = 202, array $body = ['message' => 'Accepted']): void
    {
        config([
            'langfuse.enabled' => true,
            'langfuse.host' => 'https://cloud.langfuse.com',
            'langfuse.public_key' => 'pk',
            'langfuse.secret_key' => 'sk',
            'langfuse.environment' => 'production',
        ]);
        Http::fake(['cloud.langfuse.com/*' => Http::response($body, $status)]);
    }

    public function test_posts_boolean_score_list_to_langfuse(): void
    {
        $this->enableLangfuse();

        $this->job()->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://cloud.langfuse.com/api/public/scores'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('pk:sk')
            && $request->data() === [[
                'id' => 'crm-production-ai-msg-1-u2',
                'traceId' => str_repeat('a', 32),
                'observationId' => 'bbbbbbbbbbbbbbbb',
                'name' => 'user_feedback',
                'value' => 0,
                'dataType' => 'BOOLEAN',
                'timestamp' => '2026-11-28T10:00:00Z',
                'environment' => 'production',
            ]]);
    }

    public function test_omits_observation_id_when_null(): void
    {
        $this->enableLangfuse();

        $this->job(null)->handle();

        Http::assertSent(fn (Request $request) => ! array_key_exists('observationId', $request->data()[0]));
    }

    public function test_rejected_score_fails_the_job_without_retry(): void
    {
        $this->enableLangfuse(207, ['accepted' => 0, 'rejected' => 1, 'errors' => [['message' => 'invalid traceId']]]);
        $job = $this->job()->withFakeQueueInteractions();

        $job->handle();

        $job->assertFailedWith(\RuntimeException::class);
    }

    public function test_server_error_throws_so_the_job_is_retried(): void
    {
        $this->enableLangfuse(503, []);

        $this->expectException(RequestException::class);

        $this->job()->handle();
    }

    public function test_does_nothing_when_disabled(): void
    {
        config(['langfuse.enabled' => false, 'langfuse.public_key' => 'pk', 'langfuse.secret_key' => 'sk']);
        Http::fake();

        $this->job()->handle();

        Http::assertNothingSent();
    }
}
