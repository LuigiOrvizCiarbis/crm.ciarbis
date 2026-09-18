<?php

namespace App\Jobs;

use App\Models\ProductImport;
use App\Services\ProductImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessProductImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $importId, public int $tenantId) {}

    public function handle(ProductImportService $service): void
    {
        $import = ProductImport::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->find($this->importId);
        if (! $import || $import->status !== 'queued') return;

        $claimed = ProductImport::withoutGlobalScopes()->whereKey($import->id)->where('status', 'queued')->update([
            'status' => 'processing', 'started_at' => now(), 'updated_at' => now(),
        ]);
        if ($claimed === 0) return;

        $import->refresh();
        try {
            $result = $service->runQueued($import);
            $import->update(['status' => 'completed', 'result' => $result, 'finished_at' => now()]);
        } catch (\Throwable $exception) {
            Log::error('Product import failed', ['import_id' => $import->id, 'exception' => $exception]);
            $import->update(['status' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);
        }
    }
}
