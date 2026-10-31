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

    private function decide()
    {
        return (new AnthropicProvider('sk-test'))->decide(
            [['role' => 'user', 'content' => 'quiero hablar con una persona']],
            'Sos el asistente.',
            'claude-sonnet-5-5',
        );
    }
}
