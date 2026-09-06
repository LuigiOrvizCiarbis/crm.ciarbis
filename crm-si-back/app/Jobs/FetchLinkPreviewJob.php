<?php

namespace App\Jobs;

use App\Events\MessageEdited;
use App\Models\LinkPreview;
use App\Models\Message;
use App\Services\LinkPreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Genera (o reasocia) la preview de link del primer URL detectado en un
 * mensaje. Recibe el id del mensaje, no el modelo: Message no tiene el
 * global scope de tenant y este job puede correr sin usuario autenticado.
 */
class FetchLinkPreviewJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 180];

    public function __construct(public int $messageId) {}

    public function handle(LinkPreviewService $previews): void
    {
        $message = Message::withoutTrashed()->find($this->messageId);
        if (! $message) {
            return;
        }

        $url = $previews->extractFirstUrl($message->content);
        if ($url === null) {
            return;
        }

        $urlHash = $previews->urlHash($url);

        $preview = LinkPreview::firstOrCreate(
            ['url_hash' => $urlHash],
            ['url' => $url, 'status' => 'pending'],
        );

        if (! $preview->isFresh(LinkPreviewService::FRESHNESS_DAYS)) {
            $previews->fetch($preview);
        }

        $message->update(['link_preview_id' => $preview->id]);

        broadcast(new MessageEdited($message->fresh(['linkPreview'])));
    }
}
