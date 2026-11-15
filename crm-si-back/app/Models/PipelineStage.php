<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineStage extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'pipeline_id',
        'name',
        'name_template',
        'color',
        'sort_order',
        'is_default',
        'win_probability',
        'outcome',
    ];

    protected $casts = [
        'win_probability' => 'integer',
    ];

    public const NEXT_MONTH_PLACEHOLDER = '{mes_siguiente}';

    public const OUTCOME_WON = 'won';

    public const OUTCOME_LOST = 'lost';

    // Hardcodeados para no depender de los datos de locale de Carbon.
    private const MONTH_NAMES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * Etapas por defecto del pipeline predeterminado: [nombre o plantilla,
     * color, resultado que fija a sus oportunidades (null = en curso)].
     */
    private const DEFAULT_STAGES = [
        ['Lead/Prospecto', '#3beaf7', null],
        ['Interesado', '#e6fd72', null],
        ['Contactar '.self::NEXT_MONTH_PLACEHOLDER, '#e6fd72', null],
        ['1era Reunión', '#fcdc69', null],
        ['2da Reunión', '#dfc35d', null],
        ['Negociación', '#e4992f', null],
        ['Cierre', '#9dff8a', null],
        ['Oferta/Adelanto/Reserva', '#78e25a', null],
        ['Cliente Convertido/Ganado', '#5be17d', self::OUTCOME_WON],
        ['Partner/Colega/Proveedor', '#bb3bf7', null],
        ['Perdido/No le interesa', '#fe165b', self::OUTCOME_LOST],
    ];

    /**
     * Reemplaza {mes_siguiente} por el nombre del mes siguiente, con la zona
     * horaria de la app (America/Argentina/Buenos_Aires), no la del tenant.
     */
    public static function renderNameTemplate(string $template, ?CarbonInterface $now = null): string
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone(config('app.timezone'));
        // startOfMonth() antes de addMonth() evita el desborde del día 31.
        $month = $now->startOfMonth()->addMonth()->month;

        return str_replace(self::NEXT_MONTH_PLACEHOLDER, self::MONTH_NAMES[$month], $template);
    }

    /**
     * Etapas por defecto en orden, listas para insertar (todas las filas con
     * las mismas claves, como exige insert()).
     *
     * @return list<array{name: string, name_template: ?string, color: string, sort_order: int, is_default: bool, outcome: ?string}>
     */
    public static function defaultBlueprint(): array
    {
        $stages = [];
        foreach (self::DEFAULT_STAGES as $i => [$label, $color, $outcome]) {
            $templated = str_contains($label, self::NEXT_MONTH_PLACEHOLDER);
            $stages[] = [
                'name' => $templated ? self::renderNameTemplate($label) : $label,
                'name_template' => $templated ? $label : null,
                'color' => $color,
                'sort_order' => $i + 1,
                'is_default' => $i === 0,
                'outcome' => $outcome,
            ];
        }

        return $stages;
    }

    /** Resultado (won/lost) que la etapa fija a sus oportunidades; null = en curso. */
    public static function outcomeOf(?int $stageId): ?string
    {
        if ($stageId === null) {
            return null;
        }

        return static::withoutGlobalScopes()->whereKey($stageId)->value('outcome');
    }

    protected static function booted(): void
    {
        static::creating(function (PipelineStage $stage): void {
            if (! $stage->pipeline_id && $stage->tenant_id) {
                $stage->pipeline_id = Pipeline::defaultFor($stage->tenant_id)->id;
            }
        });
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }
}
