<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Segundos de audio transcriptos por el asistente (dictado por voz).
        Schema::table('assistant_usage', function (Blueprint $table) {
            $table->decimal('audio_seconds', 10, 2)->default(0)->after('output_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_usage', function (Blueprint $table) {
            $table->dropColumn('audio_seconds');
        });
    }
};
