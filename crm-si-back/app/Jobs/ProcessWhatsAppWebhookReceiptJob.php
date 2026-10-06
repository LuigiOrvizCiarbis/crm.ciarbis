<?php

namespace App\Jobs;

use App\Http\Controllers\WhatsAppController;
use App\Models\WhatsAppWebhookReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessWhatsAppWebhookReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120];

    public int $timeout = 180;

    public function __construct(public int $receiptId, public string $queueName)
    {
        $this->onQueue($queueName);
    }

    public function handle(WhatsAppController $controller): void
    {
        $receipt = WhatsAppWebhookReceipt::find($this->receiptId);
        if (! $receipt || $receipt->status === 'completed') {
            return;
        }

        // Compare-and-set protects against queue redelivery and the recovery poller.
        $claimed = WhatsAppWebhookReceipt::whereKey($receipt->id)
            ->where(function ($query): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($stale): void {
                        $stale->where('status', 'processing')
                            ->where('processing_at', '<', now()->subMinutes(4));
                    });
            })
            ->update([
                'status' => 'processing',
                'processing_at' => now(),
                'attempts' => $receipt->attempts + 1,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if (! $claimed) {
            return;
        }

        try {
            $startedAt = microtime(true);
            $queueWaitMs = max(0, (int) $receipt->created_at->diffInMilliseconds(now()));
            $entry = $receipt->payload['entry'][0] ?? [];
            $change = $entry['changes'][0] ?? [];
            $units = $controller->expandQueuedWebhookChange($change);
            $unit = $units[$receipt->change_index] ?? null;
            if ($unit !== null) {
                $controller->processQueuedWebhookChange($entry['id'] ?? null, $unit);
            }

            $nextIndex = $receipt->change_index + 1;
            $isComplete = $nextIndex >= count($units);
            $receipt->forceFill([
                'change_index' => $nextIndex,
                'status' => $isComplete ? 'completed' : 'pending',
                'processed_at' => $isComplete ? now() : null,
                'processing_at' => null,
                'last_error' => null,
            ])->save();

            Log::info('WhatsApp webhook unit processed', [
                'receipt_id' => $receipt->id,
                'field' => $unit['field'] ?? 'unknown',
                'queue' => $receipt->queue_name,
                'unit' => $nextIndex,
                'queue_wait_ms' => $receipt->change_index === 0 ? $queueWaitMs : null,
                'processing_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            if ($isComplete) {
                Log::info('WhatsApp webhook receipt completed', [
                    'receipt_id' => $receipt->id,
                    'field' => $change['field'] ?? 'unknown',
                    'queue' => $receipt->queue_name,
                    'end_to_end_ms' => max(0, (int) $receipt->created_at->diffInMilliseconds(now())),
                ]);
            } else {
                self::dispatch($receipt->id, $receipt->queue_name);
            }
        } catch (Throwable $exception) {
            WhatsAppWebhookReceipt::whereKey($receipt->id)->update([
                'status' => 'pending',
                'processing_at' => null,
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                'updated_at' => now(),
            ]);
            Log::error('WhatsApp webhook receipt failed', [
                'receipt_id' => $receipt->id,
                'attempt' => $receipt->attempts + 1,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        WhatsAppWebhookReceipt::whereKey($this->receiptId)->update([
            'status' => 'failed',
            'processing_at' => null,
            'last_error' => mb_substr($exception?->getMessage() ?? 'Unknown queue failure', 0, 4000),
            'updated_at' => now(),
        ]);
        Log::critical('WhatsApp webhook receipt exhausted retries', [
            'receipt_id' => $this->receiptId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
