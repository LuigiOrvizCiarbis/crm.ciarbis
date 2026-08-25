<?php

namespace App\Jobs;

use App\Models\DocumentExtraction;
use App\Services\DocumentExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Extrae datos estructurados del PDF de una extracción encolada.
 *
 * - tries=1: reintentar cuesta plata. Una extracción llama al proveedor con el
 *   documento entero, así que un reintento automático paga el request dos veces
 *   sin más información que la primera vez. Si falla, el usuario reintenta.
 * - failOnTimeout: sin esto un timeout mata el proceso sin pasar por failed(),
 *   y la fila queda en processing hasta que la barra el watchdog.
 * - timeout 150 < --timeout del worker (180) para que el job corte primero y
 *   alcance a persistir el error.
 */
class ExtractDocumentDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $tenantId,
        public int $extractionId,
    ) {}

    public function handle(DocumentExtractionService $service): void
    {
        // Sin usuario autenticado TenantScope no filtra: el tenant va explícito.
        $extraction = DocumentExtraction::findForTenant($this->tenantId, $this->extractionId);

        if (! $extraction) {
            Log::warning('ExtractDocumentDataJob: extracción no encontrada en el tenant', [
                'tenant_id' => $this->tenantId,
                'extraction_id' => $this->extractionId,
            ]);

            return;
        }

        // Si otro worker ya la tomó (redelivery por retry_after), salir sin
        // llamar al proveedor: el request se pagaría dos veces.
        if (! $extraction->claim()) {
            Log::info('ExtractDocumentDataJob: la extracción ya fue tomada', [
                'extraction_id' => $extraction->id,
                'status' => $extraction->status->value,
            ]);

            return;
        }

        $result = $service->run($extraction);

        if (! $result['ok']) {
            $extraction->forceFill([
                'input_tokens' => $result['inputTokens'] ?? null,
                'output_tokens' => $result['outputTokens'] ?? null,
            ])->save();

            $extraction->markFailed($result['errorCode'], $result['errorMessage'] ?? null);

            return;
        }

        // El texto y la cobertura se guardan antes del cambio de estado: el
        // front los lee apenas ve completed.
        $extraction->forceFill([
            'fields_snapshot' => $result['fields'],
            'document_text' => $result['text'],
            'text_coverage' => $result['coverage'],
            'pages_without_text' => $result['pagesWithoutText'],
        ])->save();

        $extraction->markCompleted(
            $result['data'],
            $result['inputTokens'] ?? null,
            $result['outputTokens'] ?? null,
        );
    }

    /**
     * Persiste el fallo para que el usuario vea qué pasó en vez de un spinner
     * eterno. markFailed() no degrada un estado terminal, así que un failed()
     * tardío no pisa un completed ya escrito.
     */
    public function failed(?\Throwable $e): void
    {
        $extraction = DocumentExtraction::findForTenant($this->tenantId, $this->extractionId);

        if (! $extraction) {
            return;
        }

        $extraction->markFailed('job_failed', $e?->getMessage());

        Log::error('ExtractDocumentDataJob: job fallido', [
            'tenant_id' => $this->tenantId,
            'extraction_id' => $this->extractionId,
            'error' => $e?->getMessage(),
        ]);
    }
}
