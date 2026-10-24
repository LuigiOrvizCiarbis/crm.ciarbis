<?php

namespace App\Jobs;

use App\Services\AiTypingIndicatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshAiTypingIndicatorJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public int $conversationId,
        public int $messageId,
    ) {}

    public function handle(AiTypingIndicatorService $service): void
    {
        $service->refresh($this->conversationId, $this->messageId);
    }
}
