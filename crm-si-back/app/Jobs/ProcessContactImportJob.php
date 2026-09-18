<?php

namespace App\Jobs;

use App\Models\ProductImport;
use App\Services\ContactImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessContactImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 300;

    public function __construct(public int $importId, public int $tenantId) {}

    public function handle(ContactImportService $service): void
    {
        $import = ProductImport::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->where('resource', 'contacts')->find($this->importId);
        if (! $import || $import->status !== 'queued') return;
        if (ProductImport::withoutGlobalScopes()->whereKey($import->id)->where('status', 'queued')->update(['status' => 'processing', 'started_at' => now(), 'updated_at' => now()]) === 0) return;
        try {
            $result = $service->runQueued($import);
            $import->update(['status' => 'completed', 'result' => $result, 'finished_at' => now()]);
        } catch (\Throwable $exception) {
            Log::error('Contact import failed', ['import_id' => $import->id, 'exception' => $exception]);
            $import->update(['status' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);
        }
    }
}
