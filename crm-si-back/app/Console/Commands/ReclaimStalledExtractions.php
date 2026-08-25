<?php

namespace App\Console\Commands;

use App\Enums\ExtractionStatus;
use App\Models\DocumentExtraction;
use Illuminate\Console\Command;

/**
 * Recupera extracciones que quedaron colgadas en processing.
 *
 * El claim de un job es compare-and-set desde queued: si el worker muere a
 * mitad (OOM por un PDF pesado, deploy, kill), la fila queda en processing y
 * ningún reintento puede volver a tomarla. Sin este barrido el usuario ve un
 * spinner eterno.
 */
class ReclaimStalledExtractions extends Command
{
    protected $signature = 'extractions:reclaim';

    protected $description = 'Marca como fallidas las extracciones colgadas en processing más allá del lease';

    public function handle(): int
    {
        $lease = (int) config('services.ai.extraction.lease_seconds', 600);
        $cutoff = now()->subSeconds($lease);

        $stalled = DocumentExtraction::withoutGlobalScopes()
            ->where('status', ExtractionStatus::Processing->value)
            ->where(function ($query) use ($cutoff) {
                $query->where('processing_started_at', '<', $cutoff)
                    // Defensivo: una fila en processing sin timestamp no puede
                    // recuperarse por antigüedad, así que se usa updated_at.
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('processing_started_at')->where('updated_at', '<', $cutoff);
                    });
            })
            ->get();

        foreach ($stalled as $extraction) {
            $extraction->markFailed(
                'stalled',
                'La extracción se interrumpió. Probá de nuevo.',
            );
        }

        $count = $stalled->count();
        $this->info($count === 0
            ? 'No hay extracciones colgadas.'
            : "Se recuperaron {$count} extracciones colgadas.");

        return Command::SUCCESS;
    }
}
