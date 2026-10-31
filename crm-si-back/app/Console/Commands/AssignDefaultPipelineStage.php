<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\PipelineStage;
use Illuminate\Console\Command;

class AssignDefaultPipelineStage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'conversations:assign-default-stage
                            {--tenant= : ID del tenant específico (opcional)}
                            {--dry-run : Simular sin hacer cambios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Asigna el stage por defecto a conversaciones que no tienen uno asignado';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 Modo DRY RUN - No se realizarán cambios');
        }

        // Obtener conversaciones sin stage
        $conversationsQuery = Conversation::whereNull('pipeline_stage_id')
            ->with(['channel.tenant']);

        if ($tenantId) {
            $conversationsQuery->where('tenant_id', $tenantId);
            $this->info("Filtrando por tenant ID: {$tenantId}");
        }

        $conversations = $conversationsQuery->get();
        $totalConversations = $conversations->count();

        if ($totalConversations === 0) {
            $this->info('✅ No hay conversaciones sin stage asignado');

            return Command::SUCCESS;
        }

        $this->info("Encontradas {$totalConversations} conversaciones sin stage");
        $this->newLine();

        $updatedCount = 0;
        $errorCount = 0;

        $progressBar = $this->output->createProgressBar($totalConversations);
        $progressBar->start();

        foreach ($conversations as $conversation) {
            $tenantId = $conversation->tenant_id;

            // Buscar el stage por defecto para este tenant
            $defaultStage = PipelineStage::where('tenant_id', $tenantId)
                ->whereHas('pipeline', fn ($query) => $query->where('is_default', true))
                ->where(function ($query) {
                    $query->where('is_default', true)
                        ->orWhereNotNull('id');
                })
                ->orderByDesc('is_default')
                ->orderBy('sort_order', 'asc')
                ->first();

            if (! $defaultStage) {
                $this->error("\n⚠️  No se encontró stage para tenant {$tenantId}");
                $errorCount++;
                $progressBar->advance();

                continue;
            }

            if (! $dryRun) {
                $conversation->update(['pipeline_stage_id' => $defaultStage->id]);
            }

            $updatedCount++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info('📊 Resumen (DRY RUN):');
        } else {
            $this->info('✅ Proceso completado:');
        }

        $this->table(
            ['Métrica', 'Cantidad'],
            [
                ['Total conversaciones sin stage', $totalConversations],
                ['Conversaciones actualizadas', $updatedCount],
                ['Errores (sin stage disponible)', $errorCount],
            ]
        );

        if ($dryRun) {
            $this->warn('Para aplicar los cambios, ejecuta el comando sin --dry-run');
        }

        return Command::SUCCESS;
    }
}
