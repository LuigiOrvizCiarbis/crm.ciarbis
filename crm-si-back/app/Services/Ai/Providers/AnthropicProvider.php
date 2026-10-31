<?php

namespace App\Services\Ai\Providers;

use Anthropic\Client;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiTool;
use App\Services\Ai\AiToolset;
use App\Services\Ai\AiVerificationResult;
use App\Services\Ai\ToolLoopDeadline;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Driver de Claude (Anthropic) vía SDK oficial. El system prompt se manda
 * como bloque con cache_control ephemeral para aprovechar prompt caching.
 */
class AnthropicProvider implements AiProvider
{
    /**
     * Override de tests: transporte HTTP con MockHandler para simular las
     * respuestas de la API sin red. No usado en producción.
     */
    public static ?ClientInterface $testHttpClient = null;

    public function __construct(private string $apiKey) {}

    public function generate(array $messages, string $systemPrompt, string $model, ?AiToolset $toolset = null): ?string
    {
        try {
            $system = [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
            $messages = array_map([$this, 'formatMessage'], $messages);

            if ($toolset) {
                return $this->generateWithTools($model, $system, $messages, $toolset);
            }

            $client = new Client(
                apiKey: $this->apiKey,
                requestOptions: [
                    'transporter' => self::$testHttpClient ?? new GuzzleClient([
                        'timeout' => (int) config('services.ai.generate_timeout', 60),
                    ]),
                ],
            );

            $response = $client->messages->create(
                model: $model,
                maxTokens: 1024,
                system: $system,
                messages: $messages,
            );

            foreach ($response->content as $block) {
                if ($block->type === 'text' && trim($block->text) !== '') {
                    return trim($block->text);
                }
            }

            Log::warning('AnthropicProvider: respuesta sin bloque de texto', [
                'stop_reason' => $response->stopReason,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('AnthropicProvider: error generando respuesta', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Loop modelo → tool_use → tool_result → modelo, acotado por
     * MAX_TOOL_ROUNDS y ToolLoopDeadline. Lanza ante errores de la API: el
     * try/catch de generate() los convierte en null.
     *
     * @param  array<int, array<string, mixed>>  $system
     * @param  array<int, array<string, mixed>>  $messages  Ya en formato Anthropic.
     */
    private function generateWithTools(string $model, array $system, array $messages, AiToolset $toolset): ?string
    {
        $deadline = new ToolLoopDeadline;
        $driverTimeout = (float) config('services.ai.generate_timeout', 60);
        $tools = array_map(fn (AiTool $tool) => [
            'name' => $tool->name,
            'description' => $tool->description,
            'input_schema' => $tool->parameters,
        ], $toolset->definitions());

        for ($round = 0; ; $round++) {
            if (! $deadline->canRequest()) {
                Log::warning('AnthropicProvider: loop de herramientas cortado por tiempo', ['round' => $round]);

                return null;
            }

            // Cliente por ronda: el timeout de cada request es lo que queda del
            // presupuesto, y sin reintentos del SDK (cada uno duplicaría la espera).
            $client = new Client(
                apiKey: $this->apiKey,
                requestOptions: [
                    'transporter' => self::$testHttpClient ?? new GuzzleClient([
                        'timeout' => $deadline->requestTimeout($driverTimeout),
                    ]),
                    'maxRetries' => 0,
                ],
            );

            $response = $client->messages->create(
                model: $model,
                maxTokens: 1024,
                system: $system,
                messages: $messages,
                tools: $tools,
            );

            $toolUses = array_values(array_filter(
                $response->content,
                fn ($block) => $block->type === 'tool_use',
            ));

            if ($toolUses === []) {
                foreach ($response->content as $block) {
                    if ($block->type === 'text' && trim($block->text) !== '') {
                        return trim($block->text);
                    }
                }

                Log::warning('AnthropicProvider: respuesta sin bloque de texto', [
                    'stop_reason' => $response->stopReason,
                ]);

                return null;
            }

            // Hay llamadas pendientes: si no se pueden ejecutar, no se devuelve
            // el texto de esta respuesta (podría anunciar algo que no ocurrió).
            if ($round >= self::MAX_TOOL_ROUNDS || ! $deadline->canRunTools()) {
                Log::warning('AnthropicProvider: loop de herramientas cortado', ['round' => $round]);

                return null;
            }

            $assistantBlocks = [];
            foreach ($response->content as $block) {
                if ($block->type === 'text') {
                    $assistantBlocks[] = ['type' => 'text', 'text' => $block->text];
                } elseif ($block->type === 'tool_use') {
                    $assistantBlocks[] = [
                        'type' => 'tool_use',
                        'id' => $block->id,
                        'name' => $block->name,
                        // La API exige un objeto JSON: [] se serializaría como array.
                        'input' => $block->input === [] ? new \stdClass : $block->input,
                    ];
                }
            }

            $results = [];
            foreach ($toolUses as $block) {
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block->id,
                    'content' => $toolset->call($block->name, (array) $block->input),
                ];
            }

            $messages[] = ['role' => 'assistant', 'content' => $assistantBlocks];
            $messages[] = ['role' => 'user', 'content' => $results];
        }
    }

    public function translate(string $content, string $systemPrompt, string $model): ?string
    {
        try {
            $client = new Client(
                apiKey: $this->apiKey,
                requestOptions: [
                    'transporter' => new GuzzleClient([
                        'timeout' => (int) config('services.ai.generate_timeout', 60),
                    ]),
                ],
            );

            $response = $client->messages->create(
                model: $model,
                maxTokens: 2048,
                system: $systemPrompt,
                messages: [['role' => 'user', 'content' => $content]],
                temperature: 0,
            );

            foreach ($response->content as $block) {
                if ($block->type === 'text' && trim($block->text) !== '') {
                    return trim($block->text);
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::error('AnthropicProvider: error traduciendo texto', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Traduce un mensaje del historial al formato de la API de Anthropic. Si el
     * content es string plano, pasa sin tocar. Si es una lista de bloques
     * neutrales, mapea {type:'image'} a {type:'image', source:{base64}} y
     * {type:'text'} tal cual.
     *
     * @param  array{role: string, content: string|array<int, array<string, mixed>>}  $message
     * @return array{role: string, content: string|array<int, array<string, mixed>>}
     */
    private function formatMessage(array $message): array
    {
        if (is_string($message['content'])) {
            return $message;
        }

        $blocks = [];
        foreach ($message['content'] as $block) {
            if (($block['type'] ?? null) === 'image') {
                $blocks[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $block['mime'],
                        'data' => $block['data'],
                    ],
                ];
            } elseif (($block['type'] ?? null) === 'text') {
                $blocks[] = ['type' => 'text', 'text' => $block['text']];
            }
        }

        return ['role' => $message['role'], 'content' => $blocks];
    }

    public function listModels(): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(10)
                ->get('https://api.anthropic.com/v1/models', ['limit' => 100]);

            if (! $response->successful()) {
                Log::warning('AnthropicProvider: listModels no exitoso', [
                    'status' => $response->status(),
                ]);

                return [];
            }

            $ids = array_column($response->json('data', []), 'id');
            sort($ids);

            return array_values($ids);
        } catch (\Throwable $e) {
            Log::error('AnthropicProvider: error listando modelos', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function verify(string $systemPrompt, string $model): AiVerificationResult
    {
        $promptTokens = $this->countPromptTokens($systemPrompt, $model);

        // Un create mínimo (1 token) valida key + saldo sin gastar casi nada.
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(10)
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => $model,
                    'max_tokens' => 1,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => 'ok'],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('AnthropicProvider: verify falló (excepción)', [
                'error' => $e->getMessage(),
            ]);

            return AiVerificationResult::failure('unknown', $e->getMessage(), $promptTokens);
        }

        if ($response->successful()) {
            return AiVerificationResult::ok($promptTokens);
        }

        return $this->mapError($response, $promptTokens);
    }

    /**
     * Cuenta los tokens del system prompt vía /v1/messages/count_tokens.
     * Devuelve null si no se pudo medir (no bloquea la verificación de la key).
     */
    private function countPromptTokens(string $systemPrompt, string $model): ?int
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
                ->timeout(10)
                ->post('https://api.anthropic.com/v1/messages/count_tokens', [
                    'model' => $model,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => 'ok'],
                    ],
                ]);

            if ($response->successful()) {
                return $response->json('input_tokens');
            }
        } catch (\Throwable $e) {
            Log::warning('AnthropicProvider: count_tokens falló', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Mapea el error HTTP de Anthropic a un código legible para la UI.
     */
    private function mapError(Response $response, ?int $promptTokens): AiVerificationResult
    {
        $status = $response->status();
        $message = $response->json('error.message') ?? $response->body();

        $code = match (true) {
            $status === 401 => 'invalid_key',
            $status === 429 => 'rate_limit',
            $status === 400 && str_contains(strtolower((string) $message), 'credit balance') => 'no_credit',
            default => 'unknown',
        };

        Log::warning('AnthropicProvider: verify falló', [
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ]);

        return AiVerificationResult::failure($code, $message, $promptTokens);
    }
}
