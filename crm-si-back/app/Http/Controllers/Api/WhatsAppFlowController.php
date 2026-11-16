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
use App\Models\MediaAsset;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppTemplate;
use App\Rules\UniqueFlowName;
use App\Services\Flows\Builder\CompiledFlow;
use App\Services\Flows\Builder\FlowDefinitionCompiler;
use App\Services\Flows\Builder\FlowDefinitionValidator;
use App\Services\Flows\FlowNameTakenException;
use App\Services\Flows\FlowOpeningTemplates;
use App\Services\Flows\WhatsAppFlowEncryptionService;
use App\Services\Flows\WhatsAppFlowMessageService;
use App\Services\Flows\WhatsAppFlowService;
use App\Services\Templates\TemplateParameterException;
use App\Services\Templates\TemplateParameterRenderer;
use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class WhatsAppFlowController extends Controller
{
    public function __construct(
        private readonly WhatsAppFlowService $flows,
        private readonly WhatsAppFlowEncryptionService $encryption,
        private readonly FlowOpeningTemplates $openingTemplates,
        private readonly TemplateParameterRenderer $parameters,
        private readonly PublicUrlGuard $urlGuard,
    ) {}

    /** Tipos de imagen que Meta acepta en un mensaje. */
    private const CLOSING_IMAGE_TYPES = ['image/jpeg', 'image/png'];

    public function index(Request $request, Channel $channel): JsonResponse
    {
        $config = $this->authorizeChannel($request, $channel);
        $this->authorize('viewAny', WhatsAppFlow::class);

        $flows = WhatsAppFlow::where('whatsapp_config_id', $config->id)
            ->orderBy('name')
            ->get()
            ->when($request->boolean('sendable'), fn ($flows) => $flows->filter(fn (WhatsAppFlow $flow) => $flow->status->isSendable())->values());

        return response()->json(['data' => WhatsAppFlowResource::forAccount($flows, $request)]);
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
            // Antes de tocar Meta: un error acá no deja el formulario a medio guardar.
            $opening = $request->has('opening') ? $this->validatedOpening($flow, $request->validated('opening') ?? []) : null;
            $completion = $request->has('completion_message') ? $this->validatedCompletion($flow, $request->validated('completion_message')) : null;
            $sendDefaults = $request->has('send_defaults') ? $this->validatedSendDefaults($flow, $request->validated('send_defaults')) : null;

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

            if ($sendDefaults !== null) {
                $flow->forceFill(['send_defaults' => $sendDefaults])->save();
            }

            if ($opening !== null) {
                $flow->forceFill(['opening' => $opening])->save();
            }

            if ($request->has('completion_message')) {
                $flow->forceFill(['completion_message' => $completion])->save();
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
        $messages = app(WhatsAppFlowMessageService::class);

        try {
            $message = $request->filled('template_id')
                ? $messages->sendTemplate($conversation, $flow, $messages->openingTemplate($flow, (int) $request->validated('template_id')), $request->user())
                : $messages->send($conversation, $flow, [
                    'body' => (string) $request->validated('body'),
                    'cta' => (string) $request->validated('cta'),
                    'header' => $request->validated('header'),
                    'footer' => $request->validated('footer'),
                ], $request->user());
        } catch (InvalidArgumentException|MetaApiException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (TemplateParameterException $e) {
            return response()->json(['message' => $this->templateParameterMessage($e)], 422);
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

    /** Mensaje para el agente según el código auditable de `TemplateParameterRenderer`. */
    private function templateParameterMessage(TemplateParameterException $e): string
    {
        $code = $e->getMessage();

        if (str_starts_with($code, 'missing_parameter:')) {
            $name = substr($code, strlen('missing_parameter:'));

            return "Falta un dato del contacto para completar la variable «{$name}».";
        }

        return match ($code) {
            'media_asset_not_found' => 'El archivo del encabezado de la plantilla ya no existe. Elegí otro en la apertura del formulario.',
            'header_url_not_accessible' => 'Meta no puede descargar la URL del encabezado de la plantilla. Revisala en la apertura del formulario.',
            default => 'No se pudo completar la plantilla.',
        };
    }

    /**
     * Cada `{{nombre}}` del mensaje necesita de dónde salir (un dato del
     * contacto o un texto fijo); si no, el envío fallaría con cada contacto.
     * Se guardan sólo las variables que usa el cuerpo.
     *
     * @param  array<string, mixed>  $message
     * @return array{header: ?string, body: string, footer: ?string, cta: string, variables: list<array<string, mixed>>}
     */
    private function validatedSendDefaults(WhatsAppFlow $flow, array $message): array
    {
        $body = (string) $message['body'];
        $errors = [];

        preg_match_all('/\{\{(.*?)\}\}/', $body, $written);
        $invalid = array_filter($written[0], fn (string $variable) => preg_match(TemplateParameterRenderer::VARIABLE, $variable) !== 1);
        if ($invalid !== []) {
            $errors['send_defaults.body'][] = 'Escribí las variables en minúscula, sin espacios ni acentos, como {{nombre}}: '.implode(', ', $invalid).'.';
        }

        preg_match_all(TemplateParameterRenderer::VARIABLE, $body, $used);
        $byName = collect($message['variables'] ?? [])->keyBy(fn (array $variable) => (string) ($variable['name'] ?? ''));
        $variables = [];
        foreach (array_unique($used[1]) as $name) {
            $variable = $byName->get($name);
            if ($variable === null) {
                $errors['send_defaults.variables'][] = "Elegí de dónde sale {{{$name}}}.";

                continue;
            }
            $variables[] = ['component' => 'body', 'name' => $name, ...Arr::except($variable, ['component', 'name'])];
        }

        foreach ($this->parameters->validateParameters($variables, $flow->tenant_id) as $key => $messages) {
            $errors['send_defaults.variables.'.substr($key, strlen('parameters.'))] = $messages;
        }
        foreach ($variables as $index => $variable) {
            if (! in_array($variable['source'], ['field', 'literal'], true)) {
                $errors["send_defaults.variables.{$index}.source"][] = 'Una variable del mensaje sale de un dato del contacto o de un texto fijo.';
            } elseif ($variable['source'] === 'literal' && blank($variable['value'] ?? null)) {
                $errors["send_defaults.variables.{$index}.value"][] = 'Escribí el texto fijo.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'header' => $message['header'] ?? null,
            'body' => $body,
            'footer' => $message['footer'] ?? null,
            'cta' => (string) $message['cta'],
            'variables' => $variables,
        ];
    }

    /**
     * Las plantillas tienen que abrir este formulario y sus variables cubrir lo
     * que cada una pide. Se combina con lo guardado: las plantillas que no
     * vienen conservan su configuración.
     *
     * @param  array<string, mixed>  $opening
     * @return array{default_template_id: ?int, templates: array<string, array{parameters: list<array<string, mixed>>}>}
     */
    private function validatedOpening(WhatsAppFlow $flow, array $opening): array
    {
        $linked = $this->openingTemplates->linked($flow)->keyBy('id');
        $current = $flow->openingConfig();
        $errors = [];

        // Sólo se valida si el usuario la eligió. La guardada puede haber dejado
        // de abrir el formulario (borrada, botón cambiado): se descarta y vuelve
        // a ser automática, sin bloquear el resto del guardado.
        if (array_key_exists('default_template_id', $opening)) {
            $default = $opening['default_template_id'];
            if ($default !== null && ! $linked->has((int) $default)) {
                $errors['opening.default_template_id'][] = 'Elegí una plantilla que abra este formulario.';
            }
        } else {
            $default = $current['default_template_id'];
            if ($default !== null && ! $linked->has((int) $default)) {
                $default = null;
            }
        }

        $templates = $current['templates'];
        foreach ($opening['templates'] ?? [] as $id => $config) {
            $template = $linked->get((int) $id);
            if ($template === null) {
                $errors["opening.templates.{$id}"][] = 'Esa plantilla no abre este formulario.';

                continue;
            }

            $parameters = array_values($config['parameters'] ?? []);
            foreach ($this->parameters->validate($template, $parameters, $flow->tenant_id) as $key => $messages) {
                $errors["opening.templates.{$id}.{$key}"] = $messages;
            }
            $templates[(string) $template->id] = ['parameters' => $parameters];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['default_template_id' => $default !== null ? (int) $default : null, 'templates' => $templates];
    }

    /**
     * Una plantilla de cierre tiene que ser de la cuenta del formulario, sin
     * botón de formulario (cada respuesta abriría otro) y con sus variables
     * cubriendo lo que pide. Se puede guardar sin aprobar: no se envía hasta
     * que Meta la apruebe.
     *
     * @param  array<string, mixed>|null  $message
     * @return array<string, mixed>|null
     */
    private function validatedCompletion(WhatsAppFlow $flow, ?array $message): ?array
    {
        if ($message === null) {
            return null;
        }

        if ($message['type'] === 'text') {
            return $this->validatedTextCompletion($flow, $message);
        }

        $template = WhatsAppTemplate::withoutGlobalScopes()
            ->where('tenant_id', $flow->tenant_id)
            ->where('whatsapp_config_id', $flow->whatsapp_config_id)
            ->find($message['template_id']);

        if ($template === null) {
            throw ValidationException::withMessages(['completion_message.template_id' => 'Elegí una plantilla de la cuenta del formulario.']);
        }

        if ($template->flowButton() !== null) {
            throw ValidationException::withMessages(['completion_message.template_id' => 'Esa plantilla abre un formulario: como cierre le mandaría otro al cliente en cada respuesta. Elegí una sin botón de formulario.']);
        }

        $parameters = array_values($message['parameters'] ?? []);
        $errors = [];
        foreach ($this->parameters->validate($template, $parameters, $flow->tenant_id) as $key => $messages) {
            $errors["completion_message.{$key}"] = $messages;
        }
        foreach ($this->unpublishedAnswers($flow, $parameters) as $index => $key) {
            $errors["completion_message.parameters.{$index}.path"][] = "«{$key}» no está en la versión publicada del formulario: publicalo para usar esa respuesta.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['type' => 'template', 'template_id' => $template->id, 'parameters' => $parameters];
    }

    /**
     * La imagen se revisa al guardar: un archivo de otro espacio o que no es
     * JPG/PNG, o una URL privada, fallarían recién en cada envío.
     *
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function validatedTextCompletion(WhatsAppFlow $flow, array $message): array
    {
        $image = WhatsAppFlow::closingImage($message['image'] ?? null);

        if ($image !== null && $image['source'] === 'media_asset') {
            $asset = MediaAsset::where('tenant_id', $flow->tenant_id)->find($image['media_asset_id']);
            if ($asset === null) {
                throw ValidationException::withMessages(['completion_message.image.media_asset_id' => 'Elegí un archivo del espacio.']);
            }
            if (! in_array($asset->mime_type, self::CLOSING_IMAGE_TYPES, true)) {
                throw ValidationException::withMessages(['completion_message.image.media_asset_id' => 'WhatsApp sólo acepta imágenes JPG o PNG.']);
            }
        }

        if ($image !== null && $image['source'] === 'literal') {
            try {
                $this->urlGuard->assertPublicUrl($image['value']);
            } catch (PublicUrlRejectedException) {
                throw ValidationException::withMessages(['completion_message.image.value' => 'Usá una URL pública: Meta tiene que poder descargar la imagen.']);
            }
        }

        return [
            'type' => 'text',
            'body' => $message['body'],
            'button_text' => $message['button_text'] ?? null,
            'url' => $message['url'] ?? null,
            'image' => $image,
        ];
    }

    /**
     * Respuestas citadas (`answers.<clave>`) que la versión que corre el
     * cliente no tiene: la respuesta se lee con esa versión y llegarían
     * siempre vacías. Un formulario por JSON no declara sus claves.
     *
     * @param  list<array<string, mixed>>  $parameters
     * @return array<int, string> índice del parámetro → clave
     */
    private function unpublishedAnswers(WhatsAppFlow $flow, array $parameters): array
    {
        $live = $flow->liveVersion();
        if ($live->definition === null) {
            return [];
        }

        $known = array_column($live->responseFields(), 'key');
        $missing = [];
        foreach ($parameters as $index => $parameter) {
            $path = (string) ($parameter['path'] ?? '');
            if (($parameter['source'] ?? null) === 'field' && str_starts_with($path, 'answers.')) {
                $key = substr($path, strlen('answers.'));
                if (! in_array($key, $known, true)) {
                    $missing[$index] = $key;
                }
            }
        }

        return $missing;
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
     * Errores de negocio y de Meta se devuelven como 422 con su mensaje; un
     * nombre repetido, como error del campo para que el formulario lo marque.
     */
    private function attempt(Closure $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (FlowNameTakenException) {
            throw ValidationException::withMessages(['name' => UniqueFlowName::MESSAGE]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
