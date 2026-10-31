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
            // Turnos agendados por la IA: pending (espera aprobación del vendedor),
            // confirmed o rejected. Null en tareas creadas por personas.
            $table->string('ai_booking_status', 20)->nullable()->index();
            $table->foreignId('ai_booking_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ai_booking_decided_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_booking_decided_by');
            $table->dropIndex(['ai_booking_status']);
            $table->dropColumn(['ai_booking_status', 'ai_booking_decided_at']);
        });
    }
};
