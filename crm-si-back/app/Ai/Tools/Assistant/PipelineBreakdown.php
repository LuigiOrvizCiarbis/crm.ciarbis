<?php

namespace App\Ai\Tools\Assistant;

use App\Ai\Tools\Assistant\Concerns\RespondsSafely;
use App\Models\Opportunity;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Oportunidades abiertas por etapa de un embudo (por defecto, el principal).
 */
class PipelineBreakdown implements Tool
{
    use RespondsSafely;

    public function __construct(private User $user) {}

    public function name(): string
    {
        return 'pipeline_breakdown';
    }

    public function description(): string
    {
        return 'Foto actual del embudo: cantidad y valor de las oportunidades abiertas hoy en cada etapa, sin importar cuándo se crearon o movieron. '
            .'No filtra por período: para preguntas sobre un período (este mes, esta semana) usá activity_stats. Sin nombre usa el embudo principal.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'pipeline' => $schema->string()->description('Nombre (o parte del nombre) del embudo. Opcional.'),
        ];
    }

    public function handle(Request $request): string
    {
        return $this->respond(function () use ($request): array {
            $pipeline = $this->pipeline(trim((string) ($request['pipeline'] ?? '')));
            $totals = Opportunity::query()->visibleTo($this->user)
                ->where('pipeline_id', $pipeline->id)
                ->where('status', 'open')
                ->selectRaw('pipeline_stage_id, count(*) as open_count, coalesce(sum(value), 0) as open_value')
                ->groupBy('pipeline_stage_id')
                ->get()
                ->keyBy('pipeline_stage_id');

            return [
                'pipeline' => $pipeline->name,
                // El modelo lee esto al redactar: evita que le atribuya los
                // números al período que pidió el usuario.
                'as_of' => today()->toDateString(),
                'scope' => 'Oportunidades abiertas a la fecha, sin filtro de período',
                'stages' => $pipeline->stages()->orderBy('sort_order')->get()
                    ->map(fn (PipelineStage $stage) => [
                        'name' => $stage->name,
                        'outcome' => $stage->outcome,
                        'open_count' => (int) ($totals->get($stage->id)?->open_count ?? 0),
                        'open_value' => (float) ($totals->get($stage->id)?->open_value ?? 0),
                    ])->all(),
            ];
        });
    }

    private function pipeline(string $name): Pipeline
    {
        $match = $name === ''
            ? null
            : Pipeline::query()->whereLike('name', "%{$name}%", caseSensitive: false)->first();

        return $match ?? Pipeline::defaultFor($this->user->tenant_id);
    }
}
