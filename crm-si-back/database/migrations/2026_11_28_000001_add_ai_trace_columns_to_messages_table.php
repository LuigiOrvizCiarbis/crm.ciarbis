<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trace de Langfuse que generó un mensaje de IA: la calificación del
        // operador se envía como score a esa observación. Null en todo
        // mensaje que no escribió la IA.
        Schema::table('messages', function (Blueprint $table) {
            $table->string('ai_trace_id', 32)->nullable();
            $table->string('ai_observation_id', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['ai_trace_id', 'ai_observation_id']);
        });
    }
};
