<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageEdited;
use App\Http\Controllers\Controller;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Services\LinkPreviewService;
use Illuminate\Http\JsonResponse;

class LinkPreviewController extends Controller
{
    public function __construct(private LinkPreviewService $previews) {}

    /**
     * Reintenta la preview de link de un mensaje: si está failed/pending (o
     * vieja), vuelve a hacer el fetch. Corre síncrono para poder devolver el
     * estado final en la respuesta — el usuario está mirando el chat y
     * disparó esto a mano, así que no tiene sentido hacerle esperar un
     * round-trip de socket para ver si funcionó.
     */
    public function store(Message $message): JsonResponse
    {
        $this->authorize('view', $message);

        $url = $this->previews->extractFirstUrl($message->content);
        if ($url === null) {
            return response()->json(['message' => 'El mensaje no contiene un link.'], 422);
        }

        $urlHash = $this->previews->urlHash($url);
        $preview = LinkPreview::firstOrCreate(
            ['url_hash' => $urlHash],
            ['url' => $url, 'status' => 'pending'],
        );

        if (! $preview->isFresh(LinkPreviewService::FRESHNESS_DAYS)) {
            $this->previews->fetch($preview);
        }

        if ($message->link_preview_id !== $preview->id) {
            $message->update(['link_preview_id' => $preview->id]);
        }

        $message = $message->fresh(['linkPreview']);
        broadcast(new MessageEdited($message));

        return response()->json(['data' => $this->serialize($message->linkPreview)]);
    }

    private function serialize(LinkPreview $preview): array
    {
        return [
            'id' => $preview->id,
            'url' => $preview->url,
            'title' => $preview->title,
            'description' => $preview->description,
            'site_name' => $preview->site_name,
            'image_url' => $preview->image_url,
            'status' => $preview->status,
            'fetched_at' => $preview->fetched_at,
            'failure_reason' => $preview->failure_reason,
        ];
    }
}
