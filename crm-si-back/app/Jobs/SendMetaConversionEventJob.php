<?php

namespace App\Jobs;

use App\Models\MetaConversionEvent;
use App\Services\MetaConversionService;
use App\Support\MetaOAuth;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Avisa a Meta (Conversions API) un lead o una venta de una oportunidad cuyo
 * chat vino de un anuncio. Los fallos transitorios se reintentan.
 */
class SendMetaConversionEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 30;

    public function __construct(public int $opportunityId, public string $eventName) {}

    public function handle(MetaConversionService $service): void
    {
        $service->send($this->opportunityId, $this->eventName);
    }

    public function failed(?\Throwable $e): void
    {
        MetaConversionEvent::withoutGlobalScopes()
            ->where('opportunity_id', $this->opportunityId)
            ->where('event_name', $this->eventName)
            ->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'failed', 'error' => MetaOAuth::scrubMessage((string) $e?->getMessage())]);
    }
}
