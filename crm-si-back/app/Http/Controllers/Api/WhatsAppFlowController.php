<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelType;
use App\Exceptions\MetaApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CloneWhatsAppFlowRequest;
use App\Http\Requests\SendWhatsAppFlowRequest;
use App\Http\Requests\StoreWhatsAppFlowRequest;
use App\Http\Requests\UpdateWhatsAppFlowRequest;
use App\Http\Resources\WhatsAppFlowResource;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Services\Flows\Builder\CompiledFlow;
use App\Services\Flows\Builder\FlowDefinitionCompiler;
use App\Services\Flows\Builder\FlowDefinitionValidator;
use App\Services\Flows\WhatsAppFlowEncryptionService;
use App\Services\Flows\WhatsAppFlowMessageService;
use App\Services\Flows\WhatsAppFlowService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppFlowController extends Controller
{
    public function __construct(
        private readonly WhatsAppFlowService $flows,
        private readonly WhatsAppFlowEncryptionService $encryption,
    ) {}

    public function index(Request $request, Channel $channel): JsonResponse
    {
        $config = $this->authorizeChannel($request, $channel);
        $this->authorize('viewAny', WhatsAppFlow::class);

        $flows = WhatsAppFlow::where('whatsapp_config_id', $config->id)
            ->orderBy('name')
            ->get()
            ->when($request->boolean('sendable'), fn ($flows) => $flows->filter(fn (WhatsAppFlow $flow) => $flow->status->isSendable())->values());

        return response()->json(['data' => WhatsAppFlowResource::collection($flows)]);
    }

    public function store(StoreWhatsAppFlowRequest $request, Channel $channel): JsonResponse
    {
        $config = $this->authorizeChannel($request, $channel);
        $this->authorize('manage', WhatsAppFlow::class);
        $tenantId = (int) $channel->tenant_id;
        $name = $request->validated('name');
        $categories = $request->validated('categories');

        return $this->attempt(function () use ($request, $config, $tenantId, $name, $categories) {
            if (! $request->has('builder_definition')) {
                $flow = $this->flows->create($config, $tenantId, $name, $categories, $request->validated('flow_json'));

                return response()->json(['data' => new WhatsAppFlowResource($flow)], 201);
            }

            $definition = $request->validated('builder_definition');
            $compiled = $this->compileDefinition($definition, $tenantId);
            $flow = $this->flows->create($config, $tenantId, $name, $categories, $this->encode($compiled));
            $flow->forceFill(['builder_definition' => $definition, 'field_mappings' => $compiled->fieldMappings])->save();

            return response()->json(['data' => new WhatsAppFlowResource($flow)], 201);
        });
    }

    public function sync(Request $request, Channel $channel): JsonResponse
    {
        $config = $this->authorizeChannel($request, $channel);
        $this->authorize('manage', WhatsAppFlow::class);

        return $this->attempt(fn () => response()->json([
            'count' => $this->flows->sync($config, (int) $channel->tenant_id),
        ]));
    }

    public function encryptionStatus(Request $request, Channel $channel): JsonResponse
    {
        $config = $this->authorizeChannel($request, $channel);
        $this->authorize('viewAny', WhatsAppFlow::class);

        return response()->json(['data' => $this->encryption->status($config)]);
    }

    public function setupEncryption(Request $request, Channel $channel): JsonResponse
    {
        $config = $this->authorizeChannel($request, $channel);
        $this->authorize('manage', WhatsAppFlow::class);

        return $this->attempt(function () use ($config) {
            $this->encryption->setup($config);

            return response()->json(['data' => $this->encryption->status($config)]);
        });
    }

    public function show(Request $request, WhatsAppFlow $whatsappFlow): JsonResponse
    {
        $this->authorize('viewAny', WhatsAppFlow::class);

        return response()->json(['data' => [
            ...(new WhatsAppFlowResource($whatsappFlow))->resolve($request),
            'flow_json' => $whatsappFlow->flow_json,
            'builder_definition' => $whatsappFlow->builder_definition,
        ]]);
    }

    public function update(UpdateWhatsAppFlowRequest $request, WhatsAppFlow $whatsappFlow): JsonResponse
    {
        $this->authorize('manage', WhatsAppFlow::class);

        return $this->attempt(function () use ($request, $whatsappFlow) {
            $flow = $whatsappFlow;

            if ($request->has('name') || $request->has('categories')) {
                $flow = $this->flows->updateMetadata($flow, $request->validated('name'), $request->validated('categories'));
            }

            if ($request->has('builder_definition')) {
                $definition = $request->validated('builder_definition');
                $compiled = $this->compileDefinition($definition, $flow->tenant_id);
                $flow = $this->flows->updateJson($flow, $this->encode($compiled));
                $flow->forceFill(['builder_definition' => $definition, 'field_mappings' => $compiled->fieldMappings])->save();
            }

            if ($request->has('flow_json')) {
                $flow = $this->flows->updateJson($flow, $request->validated('flow_json'));
                $flow->forceFill(['builder_definition' => null])->save();
            }

            if ($request->has('field_mappings')) {
                if ($flow->builder_definition !== null) {
                    return response()->json(['message' => 'Los campos de un formulario del constructor se configuran en cada pregunta.'], 422);
                }

                $flow->forceFill(['field_mappings' => array_values($request->validated('field_mappings'))])->save();
            }

            if ($request->has('send_defaults')) {
                $flow->forceFill(['send_defaults' => $request->validated('send_defaults')])->save();
            }

            return response()->json(['data' => new WhatsAppFlowResource($flow)]);
        });
    }

    public function publish(WhatsAppFlow $whatsappFlow): JsonResponse
    {
        $this->authorize('manage', WhatsAppFlow::class);

        return $this->attempt(fn () => response()->json([
            'data' => new WhatsAppFlowResource($this->flows->publish($whatsappFlow)),
        ]));
    }

    public function deprecate(WhatsAppFlow $whatsappFlow): JsonResponse
    {
        $this->authorize('manage', WhatsAppFlow::class);

        return $this->attempt(fn () => response()->json([
            'data' => new WhatsAppFlowResource($this->flows->deprecate($whatsappFlow)),
        ]));
    }

    public function clone(CloneWhatsAppFlowRequest $request, WhatsAppFlow $whatsappFlow): JsonResponse
    {
        $this->authorize('manage', WhatsAppFlow::class);

        return $this->attempt(fn () => response()->json([
            'data' => new WhatsAppFlowResource($this->flows->clone($whatsappFlow, $request->validated('name'))),
        ]));
    }

    public function preview(WhatsAppFlow $whatsappFlow): JsonResponse
    {
        $this->authorize('viewAny', WhatsAppFlow::class);

        return $this->attempt(fn () => response()->json(['data' => $this->flows->previewUrl($whatsappFlow)]));
    }

    public function destroy(WhatsAppFlow $whatsappFlow): Response|JsonResponse
    {
        $this->authorize('manage', WhatsAppFlow::class);

        return $this->attempt(function () use ($whatsappFlow) {
            $this->flows->delete($whatsappFlow);

            return response()->noContent();
        });
    }

    public function send(SendWhatsAppFlowRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        if ($conversation->channel?->type !== ChannelType::WHATSAPP) {
            return response()->json(['message' => 'Los formularios sólo están disponibles para canales de WhatsApp.'], 422);
        }

        $flow = WhatsAppFlow::findOrFail($request->validated('whatsapp_flow_id'));

        try {
            $message = app(WhatsAppFlowMessageService::class)->send($conversation, $flow, [
                'body' => $request->validated('body'),
                'cta' => $request->validated('cta'),
                'header' => $request->validated('header'),
                'footer' => $request->validated('footer'),
            ], $request->user());
        } catch (InvalidArgumentException|MetaApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            Log::warning('No se pudo enviar el formulario por WhatsApp', [
                'conversation_id' => $conversation->id,
                'flow_id' => $flow->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'No se pudo enviar el formulario por WhatsApp.'], 422);
        }

        return response()->json(['data' => $message], 201);
    }

    /**
     * El acceso a formularios sigue la visibilidad del canal, igual que las
     * plantillas.
     */
    private function authorizeChannel(Request $request, Channel $channel): WhatsAppConfig
    {
        $user = $request->user();
        abort_unless($user && $channel->visibleTo($user)->whereKey($channel->id)->exists(), 403);

        $config = $channel->whatsappConfig;
        abort_if(
            $channel->type !== ChannelType::WHATSAPP || $config === null,
            422,
            'Los formularios sólo están disponibles para canales de WhatsApp.',
        );

        return $config;
    }

    /**
     * @throws ValidationException
     */
    private function compileDefinition(array $definition, int $tenantId): CompiledFlow
    {
        $errors = app(FlowDefinitionValidator::class)->validate($definition, $tenantId);

        if ($errors !== []) {
            $prefixed = [];
            foreach ($errors as $path => $messages) {
                $prefixed["builder_definition.{$path}"] = $messages;
            }

            throw ValidationException::withMessages($prefixed);
        }

        return app(FlowDefinitionCompiler::class)->compile($definition);
    }

    private function encode(CompiledFlow $compiled): string
    {
        return json_encode($compiled->flowJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Errores de negocio y de Meta se devuelven como 422 con su mensaje.
     */
    private function attempt(Closure $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
