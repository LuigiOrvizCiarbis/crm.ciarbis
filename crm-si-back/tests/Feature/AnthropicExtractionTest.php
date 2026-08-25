<?php

namespace Tests\Feature;

use App\Enums\AiProvider as AiProviderEnum;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\Providers\AnthropicProvider;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contrato de AiProvider::extract() contra respuestas simuladas del proveedor.
 *
 * El SDK de Anthropic usa su propio cliente Guzzle, así que no alcanza con
 * Http::fake(): se intercepta con un MockHandler inyectado como transporter.
 */
class AnthropicExtractionTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'monto' => ['type' => ['number', 'null']],
            'direccion' => ['type' => ['string', 'null']],
        ],
        'required' => ['monto', 'direccion'],
        'additionalProperties' => false,
    ];

    #[Test]
    public function it_returns_the_tool_input_and_the_real_token_usage(): void
    {
        $provider = $this->providerReturning([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'stop_reason' => 'tool_use',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'registrar_datos_del_documento',
                'input' => ['monto' => 150000, 'direccion' => 'Av. Corrientes 1234'],
            ]],
            'usage' => ['input_tokens' => 8321, 'output_tokens' => 96],
        ]);

        $result = $provider->extract('texto del contrato', self::SCHEMA, 'sos un extractor', 'claude-opus-5');

        $this->assertTrue($result->ok);
        $this->assertSame(150000, $result->data['monto']);
        $this->assertSame('Av. Corrientes 1234', $result->data['direccion']);
        // generate() descarta el usage; extract() lo conserva para medir costo.
        $this->assertSame(8321, $result->inputTokens);
        $this->assertSame(96, $result->outputTokens);
    }

    #[Test]
    public function it_preserves_nulls_for_data_absent_from_the_document(): void
    {
        // Un null acá significa "no está en el documento". Es el resultado que
        // el schema busca habilitar: sin él el modelo inventaría un valor.
        $provider = $this->providerReturning([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'stop_reason' => 'tool_use',
            'content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_1',
                'name' => 'registrar_datos_del_documento',
                'input' => ['monto' => null, 'direccion' => 'Av. Corrientes 1234'],
            ]],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 10],
        ]);

        $result = $provider->extract('texto', self::SCHEMA, 'prompt', 'claude-opus-5');

        $this->assertTrue($result->ok);
        $this->assertArrayHasKey('monto', $result->data);
        $this->assertNull($result->data['monto']);
    }

    #[Test]
    public function it_fails_with_invalid_output_when_the_model_answers_without_the_tool(): void
    {
        $provider = $this->providerReturning([
            'id' => 'msg_1',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'No puedo procesar este documento.']],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 12],
        ]);

        $result = $provider->extract('texto', self::SCHEMA, 'prompt', 'claude-opus-5');

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_output', $result->errorCode);
        // El usage se conserva aunque falle: el request se pagó igual.
        $this->assertSame(50, $result->inputTokens);
    }

    #[Test]
    public function it_maps_an_invalid_key_to_a_typed_error(): void
    {
        $provider = $this->providerFailing(401, ['error' => ['message' => 'invalid x-api-key']]);

        $result = $provider->extract('texto', self::SCHEMA, 'prompt', 'claude-opus-5');

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_key', $result->errorCode);
    }

    #[Test]
    public function it_maps_rate_limiting_to_a_typed_error(): void
    {
        $provider = $this->providerFailing(429, ['error' => ['message' => 'rate limit exceeded']]);

        $result = $provider->extract('texto', self::SCHEMA, 'prompt', 'claude-opus-5');

        $this->assertFalse($result->ok);
        $this->assertSame('rate_limit', $result->errorCode);
    }

    #[Test]
    public function it_never_throws_on_a_transport_failure(): void
    {
        // Un job de extracción persiste el error para mostrarlo; una excepción
        // sin mapear se vería como un 500 opaco.
        $provider = $this->providerThrowing();

        $result = $provider->extract('texto', self::SCHEMA, 'prompt', 'claude-opus-5');

        $this->assertFalse($result->ok);
        $this->assertNotNull($result->errorCode);
    }

    #[Test]
    public function openai_reports_extraction_as_unsupported_instead_of_failing_opaquely(): void
    {
        $provider = AiProviderFactory::makeWithKey(AiProviderEnum::OPENAI, 'sk-test');

        $result = $provider->extract('texto', self::SCHEMA, 'prompt', 'gpt-4o');

        $this->assertFalse($result->ok);
        $this->assertSame('unsupported', $result->errorCode);
        $this->assertStringContainsString('Claude', (string) $result->errorMessage);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function providerReturning(array $payload): AnthropicProvider
    {
        return $this->providerWith(new Response(200, ['Content-Type' => 'application/json'], json_encode($payload)));
    }

    /**
     * El SDK reintenta ante 429 y 5xx, así que se encola la misma respuesta
     * varias veces: con una sola, el MockHandler se queda sin ítems durante el
     * reintento y tira OutOfBoundsException en vez del error del proveedor.
     *
     * @param  array<string, mixed>  $payload
     */
    private function providerFailing(int $status, array $payload): AnthropicProvider
    {
        $body = json_encode($payload);
        $responses = array_fill(0, 5, fn () => new Response($status, ['Content-Type' => 'application/json'], $body));

        return $this->providerWith(array_map(static fn (callable $make) => $make(), $responses));
    }

    private function providerThrowing(): AnthropicProvider
    {
        return $this->providerWith(array_fill(0, 5, new \RuntimeException('conexión rechazada')));
    }

    /**
     * @param  Response|\Throwable|list<Response|\Throwable>  $queued
     */
    private function providerWith(Response|\Throwable|array $queued): AnthropicProvider
    {
        $handler = HandlerStack::create(new MockHandler(is_array($queued) ? $queued : [$queued]));

        // El provider construye su propio cliente Guzzle con el timeout de
        // config; se inyecta el handler por ahí.
        config(['services.ai.extraction.timeout' => 5]);

        return new class('sk-test', $handler) extends AnthropicProvider
        {
            public function __construct(string $apiKey, private HandlerStack $handler)
            {
                parent::__construct($apiKey);
            }

            protected function guzzleOptions(int $timeout): array
            {
                return ['timeout' => $timeout, 'handler' => $this->handler];
            }
        };
    }
}
