<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiExtractionResult;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiReplyDecision;
use App\Services\Ai\AiTool;
use App\Services\Ai\AiToolset;
use App\Services\Ai\AiVerificationResult;
use App\Services\Ai\ToolLoopDeadline;
use App\Services\Observability\Langfuse\LangfuseNames;
use App\Services\Observability\Langfuse\LangfuseObservation;
use App\Services\Observability\Langfuse\LangfuseTracer;
use App\Services\Observability\Langfuse\ObservationType;
use App\Services\Observability\Langfuse\UsageDetails;
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

            $generation = $this->startGeneration(LangfuseNames::RESPOND, $model, $messages, []);
            $response = $this->traced($generation, fn () => $this->client((float) config('services.ai.generate_timeout', 60))->chat()->create([
                'model' => $model,
                'max_tokens' => 1024,
                'messages' => $messages,
            ]));
            $this->endGeneration($generation, $response);

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
        $deadline = app(ToolLoopDeadline::class);
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

            $generation = $this->startGeneration(LangfuseNames::RESPOND, $model, $messages, array_map(fn (array $tool) => $tool['function']['name'], $tools));
            $response = $this->traced($generation, fn () => $this->client($deadline->requestTimeout($driverTimeout))->chat()->create([
                'model' => $model,
                'max_tokens' => 1024,
                'messages' => $messages,
                'tools' => $tools,
            ]));
            $this->endGeneration($generation, $response, $round);

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
                if (! $deadline->canRunTools()) {
                    Log::warning('OpenAiProvider: loop de herramientas cortado por tiempo', ['round' => $round]);

                    return null;
                }

                $input = json_decode($call->function->arguments, true);

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'content' => $this->callTool($toolset, $call->function->name, is_array($input) ? $input : []),
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

    /** Abre la generación de Langfuse; $messages trae el system primero (noop sin trace activo). */
    private function startGeneration(string $name, string $model, array $messages, array $toolNames): LangfuseObservation
    {
        return $this->tracer()->current()->generation($name, $model, ['max_tokens' => 1024])
            ->input(['system' => $messages[0]['content'], 'messages' => array_slice($messages, 1), 'tools' => $toolNames]);
    }

    /** Ejecuta la llamada al modelo y, si lanza, cierra la generación como fallida y relanza. */
    private function traced(LangfuseObservation $generation, \Closure $call): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            $generation->fail($e)->end();

            throw $e;
        }
    }

    /** Cierra la generación con el contenido, las tool calls y el uso de la respuesta. */
    private function endGeneration(LangfuseObservation $generation, object $response, ?int $round = null): void
    {
        $choice = $response->choices[0] ?? null;
        $generation->output([
            'content' => $choice?->message?->content,
            'tool_calls' => array_map(fn ($call) => [
                'name' => $call->function->name,
                'arguments' => $call->function->arguments,
            ], $choice?->message?->toolCalls ?? []),
        ])
            ->usage(UsageDetails::fromOpenAi($response->usage ?? null))
            ->metadata(array_filter(['stop_reason' => $choice?->finishReason, 'round' => $round], fn ($value) => $value !== null))
            ->end();
    }

    /** Ejecuta una herramienta dentro de su span de Langfuse. AiToolset::call nunca lanza. */
    private function callTool(AiToolset $toolset, string $name, array $input): string
    {
        $span = $this->tracer()->current()->child($name, ObservationType::Tool)->input($input);
        $result = $toolset->call($name, $input);
        $span->output($result)->end();

        return $result;
    }

    private function tracer(): LangfuseTracer
    {
        return app(LangfuseTracer::class);
    }

    public function decide(array $messages, string $systemPrompt, string $model, bool $offerHandoff = true): AiReplyDecision
    {
        try {
            $client = OpenAI::factory()->withApiKey($this->apiKey)->withHttpClient(new GuzzleClient(['timeout' => (int) config('services.ai.generate_timeout', 60)]))->make();
            $messages = [['role' => 'system', 'content' => $systemPrompt], ...array_map([$this, 'formatMessage'], $messages)];
            $generation = $this->startGeneration(LangfuseNames::DECIDE, $model, $messages, $offerHandoff ? ['request_human_handoff'] : []);
            $response = $this->traced($generation, fn () => $client->chat()->create([
                'model' => $model, 'max_tokens' => 1024,
                'messages' => $messages,
                ...($offerHandoff ? ['tools' => [[
                    'type' => 'function', 'function' => [
                        'name' => 'request_human_handoff',
                        'description' => self::HANDOFF_TOOL_DESCRIPTION,
                        'parameters' => self::HANDOFF_TOOL_PARAMETERS,
                    ],
                ]], 'tool_choice' => 'auto'] : []),
            ]));
            $this->endGeneration($generation, $response);
            $choice = $response->choices[0] ?? null;
            $toolCalls = $choice?->message?->toolCalls ?? [];
            foreach ($toolCalls as $call) {
                if (($call->function->name ?? null) === 'request_human_handoff') {
                    $data = json_decode((string) $call->function->arguments, true);
                    if (is_array($data)) {
                        return AiReplyDecision::handoff($data, $choice?->message?->content);
                    }
                }
            }

            return AiReplyDecision::reply($choice?->message?->content);
        } catch (\Throwable $e) {
            Log::error('OpenAiProvider: error decidiendo handoff', ['error' => $e->getMessage()]);

            return AiReplyDecision::reply(null);
        }
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

    public function summarize(string $content, string $systemPrompt, string $model, int $maxTokens): ?string
    {
        try {
            $client = OpenAI::factory()
                ->withApiKey($this->apiKey)
                ->withHttpClient(new GuzzleClient([
                    'timeout' => (int) config('services.ai.website_context.timeout', 90),
                ]))
                ->make();

            $response = $client->chat()->create([
                'model' => $model,
                'max_completion_tokens' => $maxTokens,
                'reasoning_effort' => 'minimal',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $content],
                ],
            ]);

            $summary = $response->choices[0]->message->content ?? null;

            return is_string($summary) && trim($summary) !== ''
                ? trim($summary)
                : null;
        } catch (\Throwable $e) {
            Log::error('OpenAiProvider: error resumiendo texto', [
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

    /**
     * La extracción de documentos no está habilitada para OpenAI.
     *
     * Se implementó y se revirtió: el camino quedaba escrito pero no podía
     * funcionar contra ningún modelo real, y fallar de forma opaca es peor que
     * decir que no está soportado. Faltan tres cosas, ninguna difícil, pero que
     * necesitan una key de OpenAI para verificarse:
     *
     * 1. Modelo por proveedor. Hoy services.ai.extraction.model es uno solo y
     *    su default es de Claude, así que un tenant con OpenAI recibiría un
     *    model ID de Anthropic.
     * 2. max_completion_tokens en vez de max_tokens: las familias o-series y
     *    gpt-5 rechazan el segundo. translate() en este mismo archivo ya usa
     *    la forma nueva.
     * 3. Schema propio. ExtractionSchemaBuilder emite maxLength, format y enum
     *    con null, y el modo strict de OpenAI rechaza los tres; el de Anthropic
     *    los acepta. Hay que generar un schema por proveedor o bajar strict.
     */
    public function extract(
        string $text,
        array $images,
        array $schema,
        string $systemPrompt,
        string $model,
    ): AiExtractionResult {
        return AiExtractionResult::failure(
            'unsupported',
            'La extracción de datos desde documentos requiere Claude como proveedor de IA.',
        );
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
