<?php

namespace Tests\Feature;

use App\Enums\AiBookingStatus;
use App\Enums\AiProvider as AiProviderEnum;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\AiConfig;
use App\Models\Message;
use App\Models\Task;
use App\Services\Ai\AiTool;
use App\Services\Ai\AiToolset;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Ai\ToolLoopDeadline;
use App\Services\AiReplyService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Psr\Http\Message\RequestInterface;
use Tests\Feature\Concerns\AiSchedulingFixtures;
use Tests\TestCase;

/**
 * Loop de herramientas de los drivers de IA contra respuestas HTTP simuladas.
 */
class AiToolLoopTest extends TestCase
{
    use AiSchedulingFixtures, RefreshDatabase;

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    protected function tearDown(): void
    {
        AnthropicProvider::$testHttpClient = null;
        OpenAiProvider::$testHttpClient = null;
        $this->resetSchedulingFakes();

        parent::tearDown();
    }

    public function test_anthropic_runs_tool_and_returns_final_text(): void
    {
        $this->mockAi(AnthropicProvider::class, [
            $this->anthropicMessage([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'consultar', 'input' => []]], 'tool_use'),
            $this->anthropicMessage([['type' => 'text', 'text' => 'Tengo lunes 11:00 o 11:30.']]),
        ]);
        $toolset = $this->fakeToolset();

        $reply = (new AnthropicProvider('sk-test'))->generate([['role' => 'user', 'content' => 'Quiero un turno']], 'system', 'claude-test', $toolset);

        $this->assertSame('Tengo lunes 11:00 o 11:30.', $reply);
        $this->assertSame([['consultar', []]], $toolset->calls);

        $first = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('consultar', $first['tools'][0]['name']);

        // La segunda ronda reenvía el tool_use (input como objeto JSON) y su resultado.
        $second = (string) $this->history[1]['request']->getBody();
        $this->assertStringContainsString('"input":{}', $second);
        $body = json_decode($second, true);
        $this->assertSame('tool_result', $body['messages'][2]['content'][0]['type']);
        $this->assertSame('toolu_1', $body['messages'][2]['content'][0]['tool_use_id']);
        $this->assertSame('resultado de consultar', $body['messages'][2]['content'][0]['content']);
    }

    /**
     * Sin tiempo para ejecutar herramientas, el texto que acompaña al
     * tool_use ("¡Listo, agendado!") no se envía: la reserva no ocurrió.
     */
    public function test_anthropic_cut_with_pending_tool_call_returns_null(): void
    {
        config()->set('services.ai.tool_loop_budget_seconds', 20);
        $this->mockAi(AnthropicProvider::class, [
            $this->anthropicMessage([
                ['type' => 'text', 'text' => '¡Listo, agendado!'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'agendar', 'input' => ['inicio' => '2026-07-20 11:00']],
            ], 'tool_use'),
        ]);
        $toolset = $this->fakeToolset();

        $reply = (new AnthropicProvider('sk-test'))->generate([['role' => 'user', 'content' => 'El lunes 11']], 'system', 'claude-test', $toolset);

        $this->assertNull($reply);
        $this->assertSame([], $toolset->calls);
    }

    public function test_openai_runs_tool_and_returns_final_text(): void
    {
        $this->mockAi(OpenAiProvider::class, [
            $this->openAiCompletion(null, [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'consultar', 'arguments' => '{"desde":"2026-07-21"}']]]),
            $this->openAiCompletion('Tengo martes 9:00.'),
        ]);
        $toolset = $this->fakeToolset();

        $reply = (new OpenAiProvider('sk-test'))->generate([['role' => 'user', 'content' => 'Quiero un turno']], 'system', 'gpt-test', $toolset);

        $this->assertSame('Tengo martes 9:00.', $reply);
        $this->assertSame([['consultar', ['desde' => '2026-07-21']]], $toolset->calls);

        $second = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('call_1', $second['messages'][2]['tool_calls'][0]['id']);
        $this->assertSame(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'resultado de consultar'], $second['messages'][3]);
    }

    public function test_openai_cut_with_pending_tool_call_returns_null(): void
    {
        config()->set('services.ai.tool_loop_budget_seconds', 20);
        $this->mockAi(OpenAiProvider::class, [
            $this->openAiCompletion('¡Listo, agendado!', [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'agendar', 'arguments' => '{}']]]),
        ]);
        $toolset = $this->fakeToolset();

        $this->assertNull((new OpenAiProvider('sk-test'))->generate([['role' => 'user', 'content' => 'x']], 'system', 'gpt-test', $toolset));
        $this->assertSame([], $toolset->calls);
    }

    public function test_anthropic_stops_before_second_tool_when_first_consumes_budget(): void
    {
        $clock = (object) ['now' => 1000.0];
        $this->app->instance(ToolLoopDeadline::class, new ToolLoopDeadline(80, static fn (): float => $clock->now));
        $this->mockAi(AnthropicProvider::class, [
            $this->anthropicMessage([
                ['type' => 'text', 'text' => '¡Listo, agendado!'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'consultar', 'input' => []],
                ['type' => 'tool_use', 'id' => 'toolu_2', 'name' => 'agendar', 'input' => ['inicio' => '2026-07-20 11:00']],
            ], 'tool_use'),
        ]);
        $toolset = $this->fakeToolset(static function () use ($clock): void {
            $clock->now += 60;
        });

        $reply = (new AnthropicProvider('sk-test'))->generate([['role' => 'user', 'content' => 'El lunes 11']], 'system', 'claude-test', $toolset);

        $this->assertNull($reply);
        $this->assertSame([['consultar', []]], $toolset->calls);
        $this->assertCount(1, $this->history);
    }

    public function test_openai_stops_before_second_tool_when_first_consumes_budget(): void
    {
        $clock = (object) ['now' => 1000.0];
        $this->app->instance(ToolLoopDeadline::class, new ToolLoopDeadline(80, static fn (): float => $clock->now));
        $this->mockAi(OpenAiProvider::class, [
            $this->openAiCompletion('¡Listo, agendado!', [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'consultar', 'arguments' => '{}']],
                ['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'agendar', 'arguments' => '{"inicio":"2026-07-20 11:00"}']],
            ]),
        ]);
        $toolset = $this->fakeToolset(static function () use ($clock): void {
            $clock->now += 60;
        });

        $reply = (new OpenAiProvider('sk-test'))->generate([['role' => 'user', 'content' => 'El lunes 11']], 'system', 'gpt-test', $toolset);

        $this->assertNull($reply);
        $this->assertSame([['consultar', []]], $toolset->calls);
        $this->assertCount(1, $this->history);
    }

    /**
     * El modelo reservó el turno pero falló la respuesta final: el cliente
     * igual recibe un aviso fijo del turno, en vez de nada.
     */
    public function test_reply_falls_back_to_booking_notice_when_final_generation_fails(): void
    {
        Queue::fake();
        $this->freezeMonday();
        [$tenant, $owner] = $this->makeTenantWithOwner();
        $this->connectGoogle($owner);
        $this->makeSchedulingConfig($tenant, ['mode' => 'approval']);
        $conversation = $this->makeConversation($tenant, $owner, $owner->id);
        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => SenderType::CONTACT,
            'direction' => MessageDirection::INBOUND,
            'message_type' => MessageType::Text,
            'content' => 'Quiero el lunes a las 11',
        ]);
        $aiConfig = AiConfig::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'provider' => AiProviderEnum::CLAUDE,
            'model' => 'claude-test',
            'enabled' => true,
        ]);
        $aiConfig->setEncryptedApiKey('sk-test');

        $this->mockGoogle([$this->googleEvents()]);
        $this->mockAi(AnthropicProvider::class, [
            $this->anthropicMessage([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'agendar_turno', 'input' => ['inicio' => '2026-07-20 11:00']]], 'tool_use'),
            new Response(500, ['Content-Type' => 'application/json'], '{"type":"error","error":{"type":"api_error","message":"boom"}}'),
        ]);

        $reply = app(AiReplyService::class)->respond($conversation, $aiConfig);

        $this->assertSame(AiBookingStatus::PENDING, Task::withoutGlobalScopes()->sole()->ai_booking_status);
        $this->assertSame(
            'Registramos tu pedido de turno para el lunes 20 de julio a las 11:00 hs. Un asesor lo va a confirmar a la brevedad.',
            $reply,
        );

        $system = json_decode((string) $this->history[0]['request']->getBody(), true)['system'][0]['text'];
        $this->assertStringContainsString('## Agenda de turnos', $system);
        $this->assertStringEndsWith(AiReplyService::HISTORY_OVERRIDE_RULE, $system);
    }

    /**
     * @param  class-string<AnthropicProvider|OpenAiProvider>  $provider
     * @param  list<Response>  $responses
     */
    private function mockAi(string $provider, array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $provider::$testHttpClient = new GuzzleClient(['handler' => $stack]);
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function anthropicMessage(array $content, string $stopReason = 'end_turn'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-test',
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $toolCalls
     */
    private function openAiCompletion(?string $content, array $toolCalls = []): Response
    {
        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== []) {
            $message['tool_calls'] = $toolCalls;
        }

        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'chatcmpl-'.uniqid(),
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-test',
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => $toolCalls === [] ? 'stop' : 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]));
    }

    private function fakeToolset(?\Closure $onCall = null): AiToolset
    {
        return new class($onCall) implements AiToolset
        {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = [];

            public function __construct(private ?\Closure $onCall) {}

            public function definitions(): array
            {
                return [
                    new AiTool('consultar', 'Consulta horarios', ['type' => 'object', 'properties' => ['desde' => ['type' => 'string']]]),
                    new AiTool('agendar', 'Agenda', ['type' => 'object', 'properties' => ['inicio' => ['type' => 'string']]]),
                ];
            }

            public function call(string $name, array $input): string
            {
                $this->calls[] = [$name, $input];
                if ($this->onCall !== null) {
                    ($this->onCall)();
                }

                return "resultado de {$name}";
            }
        };
    }
}
