<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            // Meta emite un status `played` la primera vez que se reproduce un
            // mensaje de voz. Hasta ahora el webhook se descartaba en silencio
            // porque no existía dónde guardarlo.
            $table->timestamp('played_at')->nullable()->after('read_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('played_at');
        });
    }
};
