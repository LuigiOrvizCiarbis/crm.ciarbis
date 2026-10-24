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

            // Stages del pipeline de ventas estándar
            $stages = [
                [
                    'name' => 'Capturados',
                    'color' => '#3B82F6',
                    'sort_order' => 1,
                    'is_default' => true,
                ],
                [
                    'name' => 'Calificados',
                    'color' => '#EAB308',
                    'sort_order' => 2,
                    'is_default' => false,
                ],
                [
                    'name' => 'Negociación',
                    'color' => '#F97316',
                    'sort_order' => 3,
                    'is_default' => false,
                ],
                [
                    'name' => 'Cierre',
                    'color' => '#8B5CF6',
                    'sort_order' => 4,
                    'is_default' => false,
                ],
                [
                    'name' => 'Ganado',
                    'color' => '#22C55E',
                    'sort_order' => 5,
                    'is_default' => false,
                ],
                [
                    'name' => 'Perdido',
                    'color' => '#EF4444',
                    'sort_order' => 6,
                    'is_default' => false,
                ],
            ];

            // Explícito: DatabaseSeeder corre WithoutModelEvents y el hook de
            // PipelineStage que asigna el pipeline predeterminado no se dispara.
            $pipelineId = Pipeline::defaultFor($tenant->id)->id;

            foreach ($stages as $stage) {
                PipelineStage::create([
                    'tenant_id' => $tenant->id,
                    'pipeline_id' => $pipelineId,
                    'name' => $stage['name'],
                    'color' => $stage['color'],
                    'sort_order' => $stage['sort_order'],
                    'is_default' => $stage['is_default'],
                ]);
            }

            $stageCount = count($stages);
            $this->command->info("✓ {$stageCount} stages creados para tenant {$tenant->id}");
        }
    }
}
