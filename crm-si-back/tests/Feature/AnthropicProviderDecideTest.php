<?php

namespace Tests\Feature;

use App\Services\Ai\Providers\AnthropicProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicProviderDecideTest extends TestCase
{
    private const TOOL_INPUT = [
        'reason' => 'customer_requested_human',
        'summary' => 'Quiere hablar con una persona.',
        'customer_locale' => 'es',
    ];

    /**
     * Claude suele escribir el texto ANTES del tool_use. Antes se devolvía el
     * primer texto como respuesta y la derivación se perdía.
     */
    public function test_text_before_tool_use_still_requests_handoff(): void
    {
        $this->fakeContent([
            ['type' => 'text', 'text' => 'Claro, te paso con alguien del equipo.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'request_human_handoff', 'input' => self::TOOL_INPUT],
        ]);

        $decision = $this->decide();

        $this->assertTrue($decision->requestsHandoff());
        $this->assertSame(self::TOOL_INPUT, $decision->handoff);
        $this->assertSame('Claro, te paso con alguien del equipo.', $decision->reply);
    }

    public function test_tool_use_without_text_has_no_message(): void
    {
        $this->fakeContent([
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'request_human_handoff', 'input' => self::TOOL_INPUT],
        ]);

        $decision = $this->decide();

        $this->assertTrue($decision->requestsHandoff());
        $this->assertNull($decision->reply);
    }

    /**
     * Al derivar, los modelos casi nunca escriben texto aparte: el aviso al
     * cliente sale del argumento obligatorio de la tool, aunque haya texto.
     */
    public function test_customer_message_from_tool_is_the_acknowledgement(): void
    {
        $this->fakeContent([
            ['type' => 'text', 'text' => 'Te derivo.'],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'request_human_handoff', 'input' => self::TOOL_INPUT + [
                'customer_message' => '  Eso no lo tengo confirmado, te paso con alguien del equipo.  ',
            ]],
        ]);

        $decision = $this->decide();

        $this->assertTrue($decision->requestsHandoff());
        $this->assertSame('Eso no lo tengo confirmado, te paso con alguien del equipo.', $decision->reply);
    }

    public function test_plain_text_is_a_reply(): void
    {
        $this->fakeContent([['type' => 'text', 'text' => '  Sí, tenemos stock.  ']]);

        $decision = $this->decide();

        $this->assertFalse($decision->requestsHandoff());
        $this->assertSame('Sí, tenemos stock.', $decision->reply);
    }

    public function test_system_prompt_is_sent_as_cached_block(): void
    {
        $this->fakeContent([['type' => 'text', 'text' => 'Hola']]);

        $this->decide();

        Http::assertSent(fn (Request $request) => $request['system'] === [[
            'type' => 'text',
            'text' => 'Sos el asistente.',
            'cache_control' => ['type' => 'ephemeral'],
        ]]);
    }

    public function test_api_error_returns_empty_reply(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

        $decision = $this->decide();

        $this->assertFalse($decision->requestsHandoff());
        $this->assertNull($decision->reply);
    }

    /**
     * Claude 5+ piensa siempre y el thinking consume max_tokens: sin effort
     * acotado y con tope chico la respuesta llegaba sin texto y el cliente
     * quedaba sin contestar.
     */
    public function test_thinking_model_request_limits_effort(): void
    {
        $this->fakeContent([['type' => 'text', 'text' => 'Hola']]);

        $this->decide('claude-opus-5-5');

        Http::assertSent(fn (Request $request) => $request['output_config'] === ['effort' => 'low']
            && $request['max_tokens'] >= 4096);
    }

    public function test_pre_thinking_model_request_keeps_default_effort(): void
    {
        $this->fakeContent([['type' => 'text', 'text' => 'Hola']]);

        $this->decide('claude-opus-4-8');

        Http::assertSent(fn (Request $request) => ! isset($request['output_config']));
    }

    public function test_thinking_without_text_is_an_empty_reply(): void
    {
        $this->fakeContent([['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']]);

        $decision = $this->decide('claude-opus-5-5');

        $this->assertFalse($decision->requestsHandoff());
        $this->assertNull($decision->reply);
    }

    private function fakeContent(array $content): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'content' => $content,
            'stop_reason' => 'tool_use',
        ])]);
    }

    private function decide(string $model = 'claude-sonnet-5-5')
    {
        return (new AnthropicProvider('sk-test'))->decide(
            [['role' => 'user', 'content' => 'quiero hablar con una persona']],
            'Sos el asistente.',
            $model,
        );
    }
}
