<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('last_activity_at');
            $table->index(['tenant_id', 'status', 'closed_at']);
        });

        // Aproximación aceptada por producto: para las ya cerradas no hay
        // registro del cierre, la última edición es lo más cercano.
        DB::table('opportunities')
            ->whereIn('status', ['won', 'lost'])
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('opportunities', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status', 'closed_at']);
            $table->dropColumn('closed_at');
        });
    }
};
