<?php

namespace App\Http\Controllers\Api;

use App\Enums\AiProvider;
use App\Http\Controllers\Controller;
use App\Models\AiConfig;
use App\Services\Ai\AiProviderFactory;
use App\Services\AiReplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiConfigController extends Controller
{
    private const SYSTEM_PROMPT_MAX_LENGTH = 20000;

    /**
     * Config de IA del tenant. Nunca devuelve la API key: solo un flag
     * has_api_key para que la UI sepa si ya hay una cargada.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('ai_config.view') && ! $user?->can('ai_config.manage')) {
            abort(403);
        }

        $config = AiConfig::where('tenant_id', $user->tenant_id)->first();

        return response()->json([
            'data' => $this->transform($config),
        ]);
    }

    /**
     * Crea o actualiza la config de IA del tenant. La API key es write-only:
     * si viene no vacía se encripta; si viene vacía o ausente, se conserva.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('ai_config.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'provider' => ['required', Rule::in(AiProvider::values())],
            'model' => ['nullable', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'human_handoff_enabled' => ['sometimes', 'boolean'],
            'system_prompt' => ['nullable', 'string', 'max:'.self::SYSTEM_PROMPT_MAX_LENGTH],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $config = AiConfig::firstOrNew(['tenant_id' => $user->tenant_id]);
        $config->tenant_id = $user->tenant_id;
        $config->provider = $validated['provider'];
        $config->model = $validated['model'] ?? null;
        $config->enabled = $validated['enabled'];
        if (array_key_exists('human_handoff_enabled', $validated)) {
            $config->human_handoff_enabled = $validated['human_handoff_enabled'];
        }
        $config->system_prompt = $validated['system_prompt'] ?? null;
        $config->save();

        // La key se setea aparte (encriptada) y solo si el usuario mandó una nueva.
        if (! empty($validated['api_key'])) {
            $config->setEncryptedApiKey($validated['api_key']);
        }

        return response()->json([
            'data' => $this->transform($config->fresh()),
            'model_vision_warning' => $this->visionWarning($config->provider, $config->model),
        ]);
    }

    /**
     * Lista los modelos disponibles para el proveedor y la API key ya guardadas
     * del tenant. Requiere una key cargada: sin ella no hay con qué consultar.
     */
    public function models(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('ai_config.view') && ! $user?->can('ai_config.manage')) {
            abort(403);
        }

        $config = AiConfig::where('tenant_id', $user->tenant_id)->first();

        if (! $config || ! $config->getDecryptedApiKey()) {
            return response()->json(['data' => []]);
        }

        $provider = AiProviderFactory::make($config);

        return response()->json([
            'data' => $provider?->listModels() ?? [],
        ]);
    }

    /**
     * Prueba la conexión con el proveedor: valida la API key, mide el system
     * prompt y distingue key inválida / saldo insuficiente / rate limit. Usa la
     * key nueva si el usuario mandó una; si no, la ya guardada. Así el tenant
     * detecta el problema al configurar, no cuando llega el primer WhatsApp.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user?->can('ai_config.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'provider' => ['required', Rule::in(AiProvider::values())],
            'model' => ['nullable', 'string', 'max:255'],
            'system_prompt' => ['nullable', 'string', 'max:'.self::SYSTEM_PROMPT_MAX_LENGTH],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $provider = AiProvider::from($validated['provider']);
        $config = AiConfig::where('tenant_id', $user->tenant_id)->first();

        // Key: la nueva del request tiene prioridad; si no, la guardada.
        $apiKey = ! empty($validated['api_key'])
            ? $validated['api_key']
            : $config?->getDecryptedApiKey();

        if (! $apiKey) {
            return response()->json([
                'data' => [
                    'ok' => false,
                    'error_code' => 'invalid_key',
                    'error_message' => 'No hay API key para probar.',
                    'prompt_tokens' => null,
                    'cache_min_tokens' => $provider->cacheMinTokens(),
                ],
            ], 422);
        }

        $model = ($validated['model'] ?? null) ?: $provider->defaultModel();
        $systemPrompt = ($validated['system_prompt'] ?? null)
            ?: $config?->system_prompt
            ?: AiReplyService::DEFAULT_SYSTEM_PROMPT;

        $driver = AiProviderFactory::makeWithKey($provider, $apiKey);

        $result = $driver->verify(trim($systemPrompt), $model);

        return response()->json([
            'data' => [
                ...$result->toArray(),
                'cache_min_tokens' => $provider->cacheMinTokens(),
                'model_vision_warning' => $this->visionWarning($provider, $model),
            ],
        ]);
    }

    /**
     * Mensaje de aviso si el modelo elegido no procesa imágenes, o null si sí.
     * El auto-responder recibe las fotos que manda el cliente por WhatsApp; con
     * un modelo sin visión esas imágenes se ignoran (se degradan a un
     * placeholder de texto). No bloquea el guardado: es solo un aviso.
     */
    private function visionWarning(AiProvider $provider, ?string $model): ?string
    {
        $effectiveModel = $model ?: $provider->defaultModel();

        if ($provider->modelSupportsVision($effectiveModel)) {
            return null;
        }

        return "El modelo {$effectiveModel} no procesa imágenes: las fotos que "
            .'envíen los clientes por WhatsApp serán ignoradas por el asistente. '
            .'Elegí un modelo con soporte de visión (por ejemplo gpt-4o o claude-opus-4-8) '
            .'si querés que el bot pueda verlas.';
    }

    /**
     * Serializa la config sin exponer la API key.
     *
     * @return array<string, mixed>
     */
    private function transform(?AiConfig $config): array
    {
        if (! $config) {
            return [
                'provider' => null,
                'model' => null,
                'enabled' => false,
                'human_handoff_enabled' => true,
                'system_prompt' => null,
                'has_api_key' => false,
            ];
        }

        return [
            'provider' => $config->provider->value,
            'model' => $config->model,
            'enabled' => $config->enabled,
            'human_handoff_enabled' => $config->human_handoff_enabled ?? true,
            'system_prompt' => $config->system_prompt,
            'has_api_key' => ! empty($config->api_key),
        ];
    }
}
