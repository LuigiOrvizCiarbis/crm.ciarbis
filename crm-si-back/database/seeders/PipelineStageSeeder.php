<?php

namespace Database\Seeders;

use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class PipelineStageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Obtener todos los tenants
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            // Verificar si ya existen stages para este tenant
            $existingStages = PipelineStage::where('tenant_id', $tenant->id)->count();

            if ($existingStages > 0) {
                $this->command->info("Tenant {$tenant->id} ({$tenant->name}) ya tiene stages configurados. Omitiendo...");

                continue;
            }

            $this->command->info("Creando stages para tenant {$tenant->id} ({$tenant->name})...");

            $stages = PipelineStage::defaultBlueprint();

            // Explícito: DatabaseSeeder corre WithoutModelEvents y el hook de
            // PipelineStage que asigna el pipeline predeterminado no se dispara.
            $pipelineId = Pipeline::defaultFor($tenant->id)->id;

            foreach ($stages as $stage) {
                PipelineStage::create([
                    ...$stage,
                    'tenant_id' => $tenant->id,
                    'pipeline_id' => $pipelineId,
                ]);
            }

            $stageCount = count($stages);
            $this->command->info("✓ {$stageCount} stages creados para tenant {$tenant->id}");
        }
    }
}
