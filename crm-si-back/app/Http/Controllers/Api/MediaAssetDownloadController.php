<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve un archivo del espacio con autenticación.
 *
 * MediaAsset::publicUrl() apunta a /storage/..., que el webserver expone sin
 * sesión: quien tenga el link abre el archivo aunque sea de otro tenant o no
 * esté logueado. Esa URL sigue existiendo porque Meta la necesita para
 * descargar los adjuntos de templates, pero la app la dejó de usar: un
 * contrato de alquiler trae DNI, domicilio y datos del garante.
 */
class MediaAssetDownloadController extends Controller
{
    /**
     * Metadata para la cabecera del visor. Va aparte de la descarga porque el
     * visor consume el archivo como binario y no puede leer un JSON del mismo
     * response; además permite mostrar nombre y tamaño mientras el PDF carga.
     */
    public function meta(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $this->authorizeAccess($request, $mediaAsset);

        return response()->json([
            'data' => [
                'id' => $mediaAsset->id,
                'name' => $mediaAsset->name,
                'mime_type' => $mediaAsset->mime_type,
                'size' => $mediaAsset->size,
                'uploaded_by' => $mediaAsset->uploader?->name,
                'created_at' => $mediaAsset->created_at,
                // El archivo puede haber sido borrado del disco con la fila viva.
                'available' => (bool) $mediaAsset->path && Storage::disk('public')->exists($mediaAsset->path),
            ],
        ]);
    }

    public function download(Request $request, MediaAsset $mediaAsset): StreamedResponse
    {
        $this->authorizeAccess($request, $mediaAsset);

        $disk = Storage::disk('public');

        // La fila puede sobrevivir al archivo: PurgeOrphanExtractionDocuments y
        // MediaAssetController::destroy borran del disco, y un custom_data puede
        // seguir apuntando al id. El front distingue este 404 del de permisos
        // para ofrecer limpiar la referencia.
        abort_unless($mediaAsset->path && $disk->exists($mediaAsset->path), 404);

        // inline por defecto (el visor lo embebe), attachment con ?download=1.
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return $disk->response($mediaAsset->path, $mediaAsset->name, [
            'Content-Type' => $mediaAsset->mime_type ?: 'application/pdf',
            // El archivo es de un tenant: ningún intermediario debe cachearlo.
            'Cache-Control' => 'private, no-store, max-age=0',
            // El PDF lo sube un tercero y se sirve inline: sin nosniff el
            // navegador puede adivinar el tipo. Lo agrega también el webserver
            // del container, pero eso es config de infra y no viaja con el
            // código: la garantía tiene que estar acá.
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }

    /**
     * Un asset vinculado a un contacto se autoriza como el contacto: quien
     * puede ver la ficha puede abrir su adjunto. No se exige
     * document_extraction.use, porque leer un archivo ya cargado no es extraer.
     *
     * Un asset sin contacto es de la biblioteca de automations y conserva su
     * permiso histórico.
     */
    private function authorizeAccess(Request $request, MediaAsset $mediaAsset): void
    {
        $user = $request->user();

        // El binding resuelve con el TenantScope del modelo, pero el chequeo va
        // explícito: si el scope se relajara, esto sigue siendo una barrera.
        abort_unless($user && $mediaAsset->tenant_id === $user->tenant_id, 404);

        if ($mediaAsset->contact_id === null) {
            abort_unless((bool) $user->can('automations.manage'), 403);

            return;
        }

        $contact = Contact::query()->find($mediaAsset->contact_id);

        abort_unless($contact !== null, 404);
        $this->authorize('view', $contact);
    }
}
