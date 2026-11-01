<?php

namespace App\Console\Commands;

use App\Models\PipelineStage;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RefreshPipelineStageMonthNames extends Command
{
    protected $signature = 'pipeline-stages:refresh-month-names';

    protected $description = 'Actualiza el nombre de las etapas con plantilla de mes (ej. "Contactar <mes siguiente>")';

    public function handle(): int
    {
        $count = 0;

        // withoutGlobalScopes: TenantScope filtra por el usuario autenticado.
        PipelineStage::withoutGlobalScopes()
            ->whereNotNull('name_template')
            ->select(['id', 'name', 'name_template'])
            ->chunkById(500, function (Collection $stages) use (&$count): void {
                foreach ($stages as $stage) {
                    $name = PipelineStage::renderNameTemplate($stage->name_template);
                    if ($stage->name === $name) {
                        continue;
                    }

                    // Compare-and-set: si entre la lectura y acá el usuario la
                    // renombró (name_template = null), su nombre gana.
                    $count += PipelineStage::withoutGlobalScopes()
                        ->whereKey($stage->id)
                        ->where('name_template', $stage->name_template)
                        ->where('name', $stage->name)
                        ->update(['name' => $name, 'updated_at' => now()]);
                }
            });

        $this->info("{$count} etapas renombradas.");

        return self::SUCCESS;
    }
}
