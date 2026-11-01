<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Cambio de horario que el cliente pidió por la IA sobre un turno
            // confirmado y que espera la aprobación de un asesor. Mismo formato
            // que starts_at/ends_at (hora de pared de la app). Null = sin pedido.
            $table->timestamp('ai_reschedule_starts_at')->nullable();
            $table->timestamp('ai_reschedule_ends_at')->nullable();
            $table->timestamp('ai_reschedule_requested_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['ai_reschedule_starts_at', 'ai_reschedule_ends_at', 'ai_reschedule_requested_at']);
        });
    }
};
