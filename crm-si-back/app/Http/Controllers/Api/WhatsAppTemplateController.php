<?php

namespace App\Http\Controllers\Api;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateWhatsAppTemplateRequest;
use App\Http\Requests\SendTemplateRequest;
use App\Http\Requests\UpdateWhatsAppTemplateRequest;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\MediaAsset;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppTemplateService;
use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppTemplateController extends Controller
{
    public function __construct(
        public WhatsAppTemplateService $templateService,
        private PublicUrlGuard $urlGuard,
    ) {}

    /** Tipos que Meta acepta en el encabezado de una plantilla, con su extensión. */
    private const HEADER_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'video/mp4' => 'mp4',
        'application/pdf' => 'pdf',
    ];

    /**
     * Listar templates aprobados de un canal.
     */
    public function index(Channel $channel): JsonResponse
    {
        $this->authorizeTemplateChannel(request(), $channel);
        $this->authorize('viewAny', WhatsAppTemplate::class);
        $waConfig = $channel->whatsappConfig;

        if (! $waConfig) {
            return response()->json(['message' => 'Este canal no tiene configuración de WhatsApp'], 404);
        }

        $templates = WhatsAppTemplate::where('whatsapp_config_id', $waConfig->id)
            ->when(request()->query('status', 'approved') !== 'all', fn ($query) => $query->approved())
            ->when(request()->filled('category'), fn ($query) => $query->where('category', request()->string('category')->toString()))
            ->with('headerMediaAsset')
            ->orderBy('name')
            ->get()
            ->each->append('default_header_asset_id');

        return response()->json($templates);
    }

    /**
     * Sincronizar templates desde Meta.
     */
    public function sync(Channel $channel): JsonResponse
    {
        $this->authorizeTemplateChannel(request(), $channel);
        $this->authorize('sync', WhatsAppTemplate::class);
        $waConfig = $channel->whatsappConfig;

        if (! $waConfig) {
            return response()->json(['message' => 'Este canal no tiene configuración de WhatsApp'], 404);
        }

        try {
            $count = $this->templateService->syncTemplates($waConfig, $channel->tenant_id);

            return response()->json([
                'message' => "Se sincronizaron {$count} templates",
                'count' => $count,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function create(CreateWhatsAppTemplateRequest $request, Channel $channel): JsonResponse
    {
        $this->authorizeTemplateChannel($request, $channel);
        $this->authorize('create', WhatsAppTemplate::class);

        $waConfig = $channel->whatsappConfig;
        if (! $waConfig) {
            return response()->json(['message' => 'Este canal no tiene configuración de WhatsApp'], 404);
        }

        try {
            $template = $this->templateService->createTemplate(
                $waConfig,
                (int) $request->user()->tenant_id,
                $request->validated(),
            );

            return response()->json($template, 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(UpdateWhatsAppTemplateRequest $request, Channel $channel, WhatsAppTemplate $template): JsonResponse
    {
        $this->authorizeTemplateChannel($request, $channel);
        $this->authorize('update', $template);

        $waConfig = $channel->whatsappConfig;
        if (! $waConfig || $template->whatsapp_config_id !== $waConfig->id) {
            abort(404);
        }

        try {
            return response()->json($this->templateService->editTemplate($waConfig, $template, $request->validated()));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function uploadTemplateMedia(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeTemplateChannel($request, $channel);
        $this->authorize('create', WhatsAppTemplate::class);

        $waConfig = $channel->whatsappConfig;
        if (! $waConfig) {
            return response()->json(['message' => 'Este canal no tiene configuración de WhatsApp'], 404);
        }

        // El ejemplo sale de un archivo subido, de un archivo del espacio o de
        // una URL pública (la imagen de la apertura de un formulario).
        $request->validate([
            'file' => 'required_without_all:media_asset_id,url|file|mimetypes:image/jpeg,image/png,video/mp4,application/pdf|max:16384',
            'media_asset_id' => 'nullable|integer',
            'url' => 'nullable|url:https|max:2000',
        ]);

        try {
            [$contents, $mimeType, $fileName] = $this->headerExample($request, $channel);
            $headerHandle = $this->templateService->uploadTemplateHeaderContents($waConfig, $contents, $mimeType, $fileName);
            $assetId = $request->filled('media_asset_id')
                ? $request->integer('media_asset_id')
                : $this->storeHeaderCopy($request, $channel, $contents, $mimeType, $fileName);

            return response()->json(['header_handle' => $headerHandle, 'media_asset_id' => $assetId], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Copia en el espacio del archivo subido o descargado de la URL: la
     * plantilla lo manda por defecto en el encabezado, porque Meta guarda el
     * ejemplo solo para revisarla y pide el archivo en cada envío.
     */
    private function storeHeaderCopy(Request $request, Channel $channel, string $contents, string $mimeType, string $fileName): int
    {
        $path = 'media-assets/'.$channel->tenant_id.'/'.Str::random(40).'.'.self::HEADER_EXTENSIONS[$mimeType];
        Storage::disk('public')->put($path, $contents);

        return MediaAsset::create([
            'tenant_id' => $channel->tenant_id,
            'uploaded_by' => $request->user()->id,
            'name' => $fileName,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => strlen($contents),
            'purpose' => MediaAsset::PURPOSE_TEMPLATE_HEADER,
        ])->id;
    }

    /**
     * @return array{string, string, string} contenido, tipo y nombre del archivo de ejemplo
     *
     * @throws \RuntimeException si el archivo no es del espacio o la URL no es pública
     */
    private function headerExample(Request $request, Channel $channel): array
    {
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            return [$file->get(), (string) $file->getMimeType(), $file->getClientOriginalName()];
        }

        if ($request->filled('media_asset_id')) {
            $asset = MediaAsset::where('tenant_id', $channel->tenant_id)->find($request->integer('media_asset_id'))
                ?? throw new \RuntimeException('Elegí un archivo del espacio.');

            return [(string) Storage::disk('public')->get($asset->path), (string) $asset->mime_type, (string) $asset->name];
        }

        $url = (string) $request->input('url');
        try {
            $this->urlGuard->assertPublicUrl($url);
        } catch (PublicUrlRejectedException) {
            throw new \RuntimeException('Usá una URL pública: Meta tiene que poder descargar el archivo.');
        }
        // Sin seguir redirecciones: una pública podría redirigir a una dirección interna.
        $response = Http::timeout(20)->withoutRedirecting()->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('No se pudo descargar el archivo de la URL.');
        }
        if (strlen($response->body()) > 16 * 1024 * 1024) {
            throw new \RuntimeException('El archivo de la URL supera los 16 MB.');
        }
        // Los únicos tipos que Meta acepta en un encabezado.
        $mimeType = strtolower(trim((string) strtok((string) $response->header('Content-Type'), ';')));
        if (! isset(self::HEADER_EXTENSIONS[$mimeType])) {
            throw new \RuntimeException('La URL tiene que ser una imagen JPG o PNG, un video MP4 o un PDF.');
        }

        return [$response->body(), $mimeType, basename((string) parse_url($url, PHP_URL_PATH)) ?: 'ejemplo'];
    }

    public function destroy(Request $request, Channel $channel, WhatsAppTemplate $template): Response|JsonResponse
    {
        $this->authorizeTemplateChannel($request, $channel);
        $this->authorize('delete', $template);

        $waConfig = $channel->whatsappConfig;
        if (! $waConfig || $template->whatsapp_config_id !== $waConfig->id) {
            abort(404);
        }

        try {
            $this->templateService->deleteTemplate($waConfig, $template);

            return response()->noContent();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Subir un archivo a Meta para usarlo como header de plantilla
     * (documento/imagen/video). Devuelve el media id de Meta.
     */
    public function uploadMedia(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeTemplateChannel($request, $channel);
        $waConfig = $channel->whatsappConfig;

        if (! $waConfig) {
            return response()->json(['message' => 'Este canal no tiene configuración de WhatsApp'], 404);
        }

        $request->validate([
            // Límites de Meta: documento 100MB, video 16MB, imagen 5MB.
            'file' => 'required|file|mimetypes:application/pdf,image/jpeg,image/png,video/mp4|max:102400',
        ]);

        try {
            $mediaId = $this->templateService->uploadMedia($waConfig, $request->file('file'));

            return response()->json(['media_id' => $mediaId], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Enviar un mensaje de template.
     */
    public function send(SendTemplateRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('send', WhatsAppTemplate::class);
        // Las plantillas son un concepto exclusivo de WhatsApp. Guard explícito
        // en el backend (el front también lo oculta, pero la defensa real es acá).
        if ($conversation->channel?->type !== ChannelType::WHATSAPP) {
            return response()->json([
                'message' => 'Las plantillas solo están disponibles para canales de WhatsApp.',
            ], 422);
        }

        $template = WhatsAppTemplate::findOrFail($request->validated('template_id'));

        if ($template->whatsapp_config_id !== $conversation->channel?->whatsapp_config_id) {
            return response()->json(['message' => 'La plantilla no pertenece a la cuenta de WhatsApp de la conversación.'], 422);
        }

        if (! $template->status->isApproved()) {
            return response()->json(['message' => 'El template no está aprobado'], 422);
        }

        try {
            $message = $this->templateService->sendTemplateMessage(
                $conversation,
                $template,
                $request->validated('components', []),
                $request->user(),
            );

            return response()->json($message, 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Template access follows the channel visibility used by conversations.
     * Members may be channel owners or assignees without having the broader
     * channels.view_any/view_assigned permission.
     */
    private function authorizeTemplateChannel(Request $request, Channel $channel): void
    {
        $user = $request->user();
        abort_unless($user && $channel->visibleTo($user)->whereKey($channel->id)->exists(), 403);
    }
}
