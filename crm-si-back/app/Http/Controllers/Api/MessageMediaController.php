<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve el adjunto de un mensaje con autenticación. Hoy media_url es una ruta
 * pública de Storage::disk('public') (/storage/...) que el webserver expone
 * sin sesión: quien tenga el link abre el archivo aunque sea de otro tenant.
 * Replica el mismo criterio de MediaAssetDownloadController::download.
 */
class MessageMediaController extends Controller
{
    /**
     * media_mime_type es lo que el remitente (o Meta) reportó al mandar el
     * archivo, sin validar contra el contenido real: no es confiable para
     * decidir si el navegador puede renderizarlo inline. Sólo estos tipos,
     * que el visor del chat efectivamente embebe, se sirven inline; cualquier
     * otro —incluido cualquier reporte de image/svg+xml o text/html, los dos
     * vectores clásicos de XSS vía archivo servido con su Content-Type— se
     * fuerza a descarga y se sirve con un Content-Type genérico.
     */
    private const INLINE_SAFE_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'audio/mpeg', 'audio/ogg', 'audio/aac', 'audio/amr',
        'video/mp4', 'video/3gpp',
    ];

    public function show(Request $request, Message $message): StreamedResponse
    {
        $this->authorize('view', $message);

        abort_if($message->media_url === null, 404);

        $disk = Storage::disk('public');
        $path = $this->diskPath($message->media_url);

        abort_unless($path !== null && $disk->exists($path), 404);

        $mimeType = $message->media_mime_type;
        $isInlineSafe = $mimeType !== null && in_array($mimeType, self::INLINE_SAFE_MIME_TYPES, true);
        $safeMimeType = $isInlineSafe ? $mimeType : 'application/octet-stream';
        $disposition = $isInlineSafe && ! $request->boolean('download') ? 'inline' : 'attachment';

        return $disk->response($path, $message->media_filename ?: basename($path), [
            'Content-Type' => $safeMimeType,
            // El archivo es de un tenant: ningún intermediario debe cachearlo.
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            // Si algo igual se renderizara inline, corre en un origen opaco
            // sin scripts ni forms: última barrera contra el mime no confiable.
            'Content-Security-Policy' => 'sandbox',
        ], $disposition);
    }

    /**
     * media_url guarda una ruta pública ("/storage/messages/...") o, para
     * adjuntos legacy, una URL absoluta. Traduce a la ruta relativa al disco
     * que Storage::exists()/response() esperan.
     */
    private function diskPath(string $mediaUrl): ?string
    {
        if (str_starts_with($mediaUrl, 'http://') || str_starts_with($mediaUrl, 'https://')) {
            $mediaUrl = (string) parse_url($mediaUrl, PHP_URL_PATH);
        }

        if (! str_starts_with($mediaUrl, '/storage/')) {
            return null;
        }

        return substr($mediaUrl, strlen('/storage/'));
    }
}
