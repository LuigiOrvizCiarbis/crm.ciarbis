<?php

namespace App\Services\Ai\Providers;

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
use OpenAI;

/**
 * Driver de OpenAI (GPT) vía SDK oficial. A diferencia de Claude, el system
 * prompt va como primer mensaje con role "system" dentro de messages.
 */
class OpenAiProvider implements AiProvider
{
    /**
     * Override de tests: cliente HTTP con MockHandler para simular las
     * respuestas de la API sin red. No usado en producción.
     */
    public static ?ClientInterface $testHttpClient = null;

    public function __construct(private string $apiKey) {}

    public function generate(array $messages, string $systemPrompt, string $model, ?AiToolset $toolset = null): ?string
    {
        try {
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ...array_map([$this, 'formatMessage'], $messages),
            ];

            if ($toolset) {
                return $this->generateWithTools($model, $messages, $toolset);
            }

            $response = $this->client((float) config('services.ai.generate_timeout', 60))->chat()->create([
                'model' => $model,
                'max_tokens' => 1024,
                'messages' => $messages,
            ]);

            $content = $response->choices[0]->message->content ?? null;

            if ($content !== null && trim($content) !== '') {
                return trim($content);
            }

            Log::warning('OpenAiProvider: respuesta sin contenido de texto', [
                'finish_reason' => $response->choices[0]->finishReason ?? null,
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('OpenAiProvider: error generando respuesta', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Loop modelo → tool_calls → role:tool → modelo, acotado por
     * MAX_TOOL_ROUNDS y ToolLoopDeadline. Lanza ante errores de la API: el
     * try/catch de generate() los convierte en null.
     *
     * @param  array<int, array<string, mixed>>  $messages  Ya en formato OpenAI, con el system primero.
     */
    private function generateWithTools(string $model, array $messages, AiToolset $toolset): ?string
    {
        $deadline = new ToolLoopDeadline;
        $driverTimeout = (float) config('services.ai.generate_timeout', 60);
        $tools = array_map(fn (AiTool $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->parameters,
            ],
        ], $toolset->definitions());

        for ($round = 0; ; $round++) {
            if (! $deadline->canRequest()) {
                Log::warning('OpenAiProvider: loop de herramientas cortado por tiempo', ['round' => $round]);

                return null;
            }

            $response = $this->client($deadline->requestTimeout($driverTimeout))->chat()->create([
                'model' => $model,
                'max_tokens' => 1024,
                'messages' => $messages,
                'tools' => $tools,
            ]);

            $message = $response->choices[0]->message;
            $toolCalls = $message->toolCalls;

            if ($toolCalls === []) {
                if ($message->content !== null && trim($message->content) !== '') {
                    return trim($message->content);
                }

                Log::warning('OpenAiProvider: respuesta sin contenido de texto', [
                    'finish_reason' => $response->choices[0]->finishReason ?? null,
                ]);

                return null;
            }

            // Hay llamadas pendientes: si no se pueden ejecutar, no se devuelve
            // el texto de esta respuesta (podría anunciar algo que no ocurrió).
            if ($round >= self::MAX_TOOL_ROUNDS || ! $deadline->canRunTools()) {
                Log::warning('OpenAiProvider: loop de herramientas cortado', ['round' => $round]);

                return null;
            }

            $messages[] = [
                'role' => 'assistant',
                'content' => $message->content,
                'tool_calls' => array_map(fn ($call) => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->function->name,
                        'arguments' => $call->function->arguments,
                    ],
                ], $toolCalls),
            ];

            foreach ($toolCalls as $call) {
                $input = json_decode($call->function->arguments, true);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'content' => $toolset->call($call->function->name, is_array($input) ? $input : []),
                ];
            }
        }
    }

    private function client(float $timeout): OpenAI\Client
    {
        return OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withHttpClient(self::$testHttpClient ?? new GuzzleClient(['timeout' => $timeout]))
            ->make();
    }

    public function translate(string $content, string $systemPrompt, string $model): ?string
    {
        try {
            $client = OpenAI::factory()
                ->withApiKey($this->apiKey)
                ->withHttpClient(new GuzzleClient([
                    'timeout' => (int) config('services.ai.generate_timeout', 60),
                ]))
                ->make();

            $response = $client->chat()->create([
                'model' => $model,
                'max_completion_tokens' => 2048,
                'reasoning_effort' => 'minimal',
                'verbosity' => 'low',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $content],
                ],
            ]);

            $translated = $response->choices[0]->message->content ?? null;

            return is_string($translated) && trim($translated) !== ''
                ? trim($translated)
                : null;
        } catch (\Throwable $e) {
            Log::error('OpenAiProvider: error traduciendo texto', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Traduce un mensaje del historial al formato de la API de OpenAI. Si el
     * content es string plano, pasa sin tocar. Si es una lista de bloques
     * neutrales, mapea {type:'image'} a {type:'image_url', image_url:{data URI}}
     * y {type:'text'} tal cual.
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
                    'type' => 'image_url',
                    'image_url' => ['url' => "data:{$block['mime']};base64,{$block['data']}"],
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
            $response = Http::withToken($this->apiKey)
                ->timeout(10)
                ->get('https://api.openai.com/v1/models');

            if (! $response->successful()) {
                Log::warning('OpenAiProvider: listModels no exitoso', [
                    'status' => $response->status(),
                ]);

                return [];
            }

            // /v1/models trae de todo (embeddings, whisper, tts, dall-e...).
            // Filtramos a modelos que sirven para chat completions.
            $ids = array_filter(
                array_column($response->json('data', []), 'id'),
                fn (string $id) => str_starts_with($id, 'gpt-')
                    || str_starts_with($id, 'chatgpt-')
                    || (bool) preg_match('/^o\d/', $id),
            );

            sort($ids);

            return array_values($ids);
        } catch (\Throwable $e) {
            Log::error('OpenAiProvider: error listando modelos', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function verify(string $systemPrompt, string $model): AiVerificationResult
    {
        // OpenAI no expone un endpoint de conteo de tokens, así que solo
        // validamos key + saldo con un create mínimo. prompt_tokens queda null.
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(10)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'max_tokens' => 1,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => 'ok'],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::error('OpenAiProvider: verify falló (excepción)', [
                'error' => $e->getMessage(),
            ]);

            return AiVerificationResult::failure('unknown', $e->getMessage());
        }

        if ($response->successful()) {
            return AiVerificationResult::ok();
        }

        return $this->mapError($response);
    }

    /**
     * Mapea el error HTTP de OpenAI a un código legible para la UI.
     */
    private function mapError(Response $response): AiVerificationResult
    {
        $status = $response->status();
        $message = $response->json('error.message') ?? $response->body();
        $type = $response->json('error.type');

        $code = match (true) {
            $status === 401 => 'invalid_key',
            $status === 429 && $type === 'insufficient_quota' => 'no_credit',
            $status === 429 => 'rate_limit',
            default => 'unknown',
        };

        Log::warning('OpenAiProvider: verify falló', [
            'status' => $status,
            'code' => $code,
            'message' => $message,
        ]);

        return AiVerificationResult::failure($code, $message);
    }
}
