<?php

namespace App\Console\Commands;

use App\Models\ContactField;
use App\Models\DocumentExtraction;
use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Borra los PDFs subidos para extraer que nunca llegaron a usarse.
 *
 * El upload y el encolado son dos requests: si el usuario cierra el diálogo
 * entre uno y otro, el archivo queda en disco sin que nada lo referencie. Sin
 * este barrido se acumulan indefinidamente.
 *
 * Sólo toca assets con purpose 'extraction' y más viejos que el TTL. Nunca
 * borra uno referenciado por una extracción ni por el valor de un campo File
 * de algún contacto: ese archivo es un adjunto legítimo del CRM.
 */
class PurgeOrphanExtractionDocuments extends Command
{
    protected $signature = 'extractions:purge-orphans {--dry-run : Sólo listar, sin borrar}';

    protected $description = 'Borra documentos subidos para extracción que quedaron sin usar';

    public function handle(): int
    {
        $ttlHours = (int) config('services.ai.extraction.orphan_ttl_hours', 24);
        $cutoff = now()->subHours($ttlHours);
        $dryRun = (bool) $this->option('dry-run');

        $candidates = MediaAsset::withoutGlobalScopes()
            ->where('purpose', MediaAsset::PURPOSE_EXTRACTION)
            ->where('created_at', '<', $cutoff)
            // Referenciado por una extracción: es el documento de una corrida,
            // aunque haya fallado — el usuario puede querer ver qué pasó.
            ->whereNotIn('id', DocumentExtraction::withoutGlobalScopes()->select('media_asset_id'))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No hay documentos huérfanos para borrar.');

            return Command::SUCCESS;
        }

        // Un asset puede haber terminado en un campo File del contacto (el
        // usuario lo adjuntó además de extraerlo). Ahí deja de ser huérfano.
        $referenced = $this->idsReferencedByFileFields($candidates->pluck('tenant_id')->unique()->all());

        $deleted = 0;
        foreach ($candidates as $asset) {
            if (in_array((int) $asset->id, $referenced, true)) {
                continue;
            }

            if ($dryRun) {
                $this->line("borraría #{$asset->id} {$asset->name} (tenant {$asset->tenant_id})");
                $deleted++;

                continue;
            }

            Storage::disk('public')->delete($asset->path);
            $asset->delete();
            $deleted++;
        }

        $this->info($dryRun
            ? "{$deleted} documentos huérfanos se borrarían."
            : "Se borraron {$deleted} documentos huérfanos.");

        return Command::SUCCESS;
    }

    /**
     * Ids de MediaAsset usados como valor de algún campo File.
     *
     * Se consulta por tenant porque el schema de campos es por tenant: hay que
     * saber qué claves de custom_data son de tipo File antes de leerlas.
     *
     * @param  list<int>  $tenantIds
     * @return list<int>
     */
    private function idsReferencedByFileFields(array $tenantIds): array
    {
        $referenced = [];

        foreach ($tenantIds as $tenantId) {
            $fileKeys = ContactField::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('type', 'file')
                ->pluck('key');

            foreach ($fileKeys as $key) {
                // La key es un slug generado por el CRM, pero igual va como
                // binding: nunca interpolada en el SQL.
                $rows = DB::table('contacts')
                    ->where('tenant_id', $tenantId)
                    ->whereRaw('custom_data ->> ? IS NOT NULL', [$key])
                    ->selectRaw('custom_data ->> ? as asset_id', [$key])
                    ->get();

                foreach ($rows as $row) {
                    if (is_numeric($row->asset_id)) {
                        $referenced[] = (int) $row->asset_id;
                    }
                }
            }
        }

        return array_values(array_unique($referenced));
    }
}
