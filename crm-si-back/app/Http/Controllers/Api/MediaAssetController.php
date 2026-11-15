<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaAssetController extends Controller
{
    // Topes de Meta por tipo para los adjuntos de plantillas y mensajes: PDF
    // (100 MB), video MP4 (16 MB) e imagen JPG/PNG (5 MB). El archivo igual
    // viaja por HTTP hasta el CRM antes de guardarse.
    private const MAX_KILOBYTES_BY_TYPE = [
        'application/pdf' => 100 * 1024,
        'video/mp4' => 16 * 1024,
        'image/jpeg' => 5 * 1024,
        'image/png' => 5 * 1024,
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $assets = MediaAsset::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (MediaAsset $asset): array => $this->serialize($asset))
            ->all();

        return response()->json(['data' => $assets]);
    }

    public function store(Request $request): JsonResponse
    {
        // Los formularios también suben archivos: encabezados de plantilla e imagen del cierre.
        abort_unless((bool) $request->user()?->canAny(['automations.manage', 'flows.manage']), 403);

        $validated = $request->validate([
            'file' => ['bail', 'required', 'file', 'mimetypes:'.implode(',', array_keys(self::MAX_KILOBYTES_BY_TYPE)), $this->withinTypeLimit(...)],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $file = $request->file('file');
        $path = $file->store('media-assets/'.$request->user()->tenant_id, 'public');

        $asset = MediaAsset::create([
            'tenant_id' => $request->user()->tenant_id,
            'uploaded_by' => $request->user()->id,
            'name' => $validated['name'] ?? $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/pdf',
            'size' => $file->getSize(),
        ]);

        return response()->json(['data' => $this->serialize($asset)], 201);
    }

    private function withinTypeLimit(string $attribute, mixed $file, \Closure $fail): void
    {
        $limit = self::MAX_KILOBYTES_BY_TYPE[$file->getMimeType()] ?? null;
        if ($limit !== null && $file->getSize() > $limit * 1024) {
            $fail('El archivo supera el máximo de '.intdiv($limit, 1024).' MB que acepta WhatsApp para este tipo.');
        }
    }

    public function destroy(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $this->authorizeManage($request);

        Storage::disk('public')->delete($mediaAsset->path);
        $mediaAsset->delete();

        return response()->json([], 204);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless((bool) $request->user()?->can('automations.manage'), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(MediaAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'mime_type' => $asset->mime_type,
            'size' => $asset->size,
            'url' => $asset->publicUrl(),
            'created_at' => $asset->created_at,
        ];
    }
}
