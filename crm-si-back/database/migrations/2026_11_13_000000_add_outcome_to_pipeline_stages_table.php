<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Nombres de fábrica actuales y heredados de las etapas de cierre. */
    private const STAGE_NAMES = [
        'won' => ['Cliente Convertido/Ganado', 'Ganado'],
        'lost' => ['Perdido/No le interesa', 'Perdido'],
    ];

    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->string('outcome', 8)->nullable()->after('win_probability');
        });

        foreach (self::STAGE_NAMES as $outcome => $names) {
            DB::table('pipeline_stages')->whereIn('name', $names)->update(['outcome' => $outcome]);
        }

        // Las oportunidades abiertas que ya están en una etapa de cierre toman
        // su resultado. Query builder: no dispara eventos ni avisa a Meta.
        foreach (array_keys(self::STAGE_NAMES) as $outcome) {
            DB::table('opportunities')
                ->where('status', 'open')
                ->whereIn('pipeline_stage_id', DB::table('pipeline_stages')->where('outcome', $outcome)->select('id'))
                ->update(['status' => $outcome, 'closed_at' => DB::raw('COALESCE(closed_at, updated_at)')]);
        }
    }

    public function down(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table): void {
            $table->dropColumn('outcome');
        });
    }
};
