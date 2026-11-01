<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pasa a las 11 etapas nuevas los pipelines predeterminados que siguen con las
 * 6 etapas originales sin tocar. Actualiza las 6 filas en el lugar (conservan
 * su id, así conversaciones y oportunidades no cambian de etapa) e inserta las
 * 5 que faltan.
 *
 * Todo lo que define el resultado está congelado acá y no se lee de
 * PipelineStage: si el blueprint del modelo cambia, esta migración debe seguir
 * haciendo lo mismo.
 */
return new class extends Migration
{
    private const PLACEHOLDER = '{mes_siguiente}';

    /** Nombre original => sort_order nuevo. El orden de las claves es el original. */
    private const LEGACY_TO_SORT_ORDER = [
        'Capturados' => 1,
        'Calificados' => 2,
        'Negociación' => 6,
        'Cierre' => 7,
        'Ganado' => 9,
        'Perdido' => 11,
    ];

    /**
     * Colores de fábrica de las 6 etapas originales. El hook de Tenant no
     * asignaba color (quedaba el default de la columna); el seeder usaba una
     * paleta fija. Cualquier otro color es una personalización.
     */
    private const FACTORY_PALETTES = [
        ['#3B82F6', '#3B82F6', '#3B82F6', '#3B82F6', '#3B82F6', '#3B82F6'],
        ['#3B82F6', '#EAB308', '#F97316', '#8B5CF6', '#22C55E', '#EF4444'],
    ];

    /** sort_order => [nombre o plantilla, color]. */
    private const STAGES = [
        1 => ['Lead/Prospecto', '#3beaf7'],
        2 => ['Interesado', '#e6fd72'],
        3 => ['Contactar '.self::PLACEHOLDER, '#e6fd72'],
        4 => ['1era Reunión', '#fcdc69'],
        5 => ['2da Reunión', '#dfc35d'],
        6 => ['Negociación', '#e4992f'],
        7 => ['Cierre', '#9dff8a'],
        8 => ['Oferta/Adelanto/Reserva', '#78e25a'],
        9 => ['Cliente Convertido/Ganado', '#5be17d'],
        10 => ['Partner/Colega/Proveedor', '#bb3bf7'],
        11 => ['Perdido/No le interesa', '#fe165b'],
    ];

    private const MONTH_NAMES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function up(): void
    {
        $pipelineIds = DB::table('pipelines')->where('is_default', true)->orderBy('id')->pluck('id');

        foreach ($pipelineIds as $pipelineId) {
            DB::transaction(fn () => $this->upgradePipeline($pipelineId));
        }
    }

    public function down(): void
    {
        // Cambio de datos irreversible: no se puede distinguir una etapa
        // convertida de una creada después. El rollback del schema vive en
        // 2026_10_31_000000_add_name_template_to_pipeline_stages_table.
    }

    private function upgradePipeline(int $pipelineId): void
    {
        // El lock del pipeline también frena altas de etapas concurrentes (el
        // FK de la etapa nueva necesita un lock compatible sobre esta fila).
        $pipeline = DB::table('pipelines')->where('id', $pipelineId)->lockForUpdate()->first(['id', 'tenant_id']);
        if ($pipeline === null) {
            return;
        }

        $stages = DB::table('pipeline_stages')
            ->where('pipeline_id', $pipelineId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'name', 'color', 'is_default']);

        if (! $this->isUntouched($stages)) {
            return;
        }

        $now = now();
        foreach ($stages as $stage) {
            DB::table('pipeline_stages')
                ->where('id', $stage->id)
                ->update([...$this->row(self::LEGACY_TO_SORT_ORDER[$stage->name]), 'updated_at' => $now]);
        }

        $missing = array_diff(array_keys(self::STAGES), array_values(self::LEGACY_TO_SORT_ORDER));
        DB::table('pipeline_stages')->insert(array_map(fn (int $sortOrder) => [
            ...$this->row($sortOrder),
            'tenant_id' => $pipeline->tenant_id,
            'pipeline_id' => $pipeline->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values($missing)));
    }

    /**
     * Sin tocar = exactamente las 6 etapas originales, en su orden, con la
     * entrada en la primera y una paleta de fábrica. Renombrar, reordenar,
     * agregar, borrar o recolorear cuenta como personalizado.
     */
    private function isUntouched(Collection $stages): bool
    {
        if ($stages->pluck('name')->all() !== array_keys(self::LEGACY_TO_SORT_ORDER)) {
            return false;
        }

        $defaults = $stages->map(fn ($stage) => (bool) $stage->is_default)->all();
        if ($defaults !== [true, false, false, false, false, false]) {
            return false;
        }

        $colors = $stages->map(fn ($stage) => strtoupper((string) $stage->color))->all();

        return in_array($colors, self::FACTORY_PALETTES, true);
    }

    /** @return array{name: string, name_template: ?string, color: string, sort_order: int, is_default: bool} */
    private function row(int $sortOrder): array
    {
        [$label, $color] = self::STAGES[$sortOrder];
        $templated = str_contains($label, self::PLACEHOLDER);

        return [
            'name' => $templated ? $this->render($label) : $label,
            'name_template' => $templated ? $label : null,
            'color' => $color,
            'sort_order' => $sortOrder,
            'is_default' => $sortOrder === 1,
        ];
    }

    private function render(string $template): string
    {
        $month = now()->setTimezone(config('app.timezone'))->startOfMonth()->addMonth()->month;

        return str_replace(self::PLACEHOLDER, self::MONTH_NAMES[$month], $template);
    }
};
