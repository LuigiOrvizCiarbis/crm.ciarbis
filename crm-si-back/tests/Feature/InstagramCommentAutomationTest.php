<?php

namespace Tests\Feature;

use App\Automation\AutomationEngine;
use App\Automation\AutomationRegistry;
use App\Automation\DateAutomationScheduler;
use App\Enums\AutomationRuleStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\ChannelType;
use App\Enums\UserRole;
use App\Jobs\EvaluateAutomationEventJob;
use App\Models\AutomationRule;
use App\Models\Channel;
use App\Models\InstagramComment;
use App\Models\InstagramConfig;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstagramCommentAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_matching_keyword_sends_one_private_reply(): void
    {
        Queue::fake();
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['message_id' => 'ig_mid_1'], 200),
        ]);

        [$channel, $user] = $this->instagramChannel();
        $rule = $this->activeRule($channel, $user, ['catálogo', 'precio']);
        $comment = $this->comment($channel, '¡Hola! Quiero el CATALOGO, por favor.');

        $job = new EvaluateAutomationEventJob($channel->tenant_id, $this->event($comment));
        $job->handle(app(AutomationRegistry::class), app(DateAutomationScheduler::class));

        $run = $rule->runs()->firstOrFail();
        app(AutomationEngine::class)->execute($run);

        $this->assertSame(AutomationRunStatus::Succeeded, $run->fresh()->status);
        $this->assertSame('ig_mid_1', $comment->fresh()->private_reply_external_id);
        $this->assertNull($comment->fresh()->private_reply_claimed_at);
        $this->assertNotNull($comment->fresh()->conversation_id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['recipient']['comment_id'] === $comment->external_id
            && $request['message']['text'] === 'Te mando el catálogo por privado.');

        // El mismo evento no crea otra ejecución ni otro envío.
        $job->handle(app(AutomationRegistry::class), app(DateAutomationScheduler::class));
        $this->assertSame(1, $rule->runs()->count());
        Http::assertSentCount(1);
    }

    public function test_keyword_must_match_complete_words_and_the_configured_channel(): void
    {
        Queue::fake();
        [$channel, $user] = $this->instagramChannel();
        $rule = $this->activeRule($channel, $user, ['hola']);

        $comment = $this->comment($channel, 'Estoy buscando información sobre Holanda');
        (new EvaluateAutomationEventJob($channel->tenant_id, $this->event($comment)))
            ->handle(app(AutomationRegistry::class), app(DateAutomationScheduler::class));

        $this->assertSame(0, $rule->runs()->count());
    }

    /** @return array{Channel, User} */
    private function instagramChannel(): array
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::ADMIN]);
        $config = InstagramConfig::create([
            'tenant_id' => $tenant->id,
            'ig_user_id' => 'IG_BIZ_AUTOMATION',
            'page_id' => 'PAGE_AUTOMATION',
            'webhook_object_id' => 'IG_BIZ_AUTOMATION',
            'username' => 'acme',
            'page_access_token' => Crypt::encryptString('PAGE_TOKEN'),
        ]);
        $channel = Channel::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'instagram_config_id' => $config->id,
            'type' => ChannelType::INSTAGRAM,
            'external_id' => 'IG_BIZ_AUTOMATION',
            'name' => '@acme',
            'status' => 'active',
        ]);

        return [$channel, $user];
    }

    private function activeRule(Channel $channel, User $user, array $keywords): AutomationRule
    {
        $rule = AutomationRule::create([
            'tenant_id' => $channel->tenant_id,
            'created_by' => $user->id,
            'name' => 'Enviar catálogo',
            'status' => AutomationRuleStatus::Active,
            'trigger_type' => 'instagram.comment_keyword',
            'trigger_config' => ['channel_id' => $channel->id, 'keywords' => $keywords],
            'timezone' => 'America/Argentina/Buenos_Aires',
            'activated_at' => now(),
        ]);
        $rule->actions()->create([
            'position' => 0,
            'type' => 'instagram_private_reply',
            'config' => [
                'channel_id' => $channel->id,
                'message' => 'Te mando el catálogo por privado.',
            ],
        ]);

        return $rule;
    }

    private function comment(Channel $channel, string $text): InstagramComment
    {
        return InstagramComment::create([
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'external_id' => 'comment_'.Str::random(8),
            'author_external_id' => 'IGSID_COMMENTER',
            'author_username' => 'ana',
            'text' => $text,
            'commented_at' => now(),
            'private_reply_deadline' => now()->addDays(7),
        ]);
    }

    private function event(InstagramComment $comment): array
    {
        return [
            'id' => (string) Str::uuid(),
            'type' => 'instagram.comment_keyword',
            'subject_type' => 'instagram_comment',
            'subject_id' => $comment->id,
            'channel_id' => $comment->channel_id,
            'text' => $comment->text,
        ];
    }
}
