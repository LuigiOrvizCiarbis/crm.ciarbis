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
        Schema::create('ai_scheduling_configs', function (Blueprint $table) {
            $table->id();
            // Una config de agenda de turnos de la IA por tenant.
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 20)->default('approval');
            $table->text('criteria')->nullable();
            // Calendario a usar cuando el vendedor asignado no tiene Google conectado.
            $table->foreignId('default_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('timezone')->default('America/Argentina/Buenos_Aires');
            $table->unsignedSmallInteger('meeting_duration_minutes')->default(30);
            $table->unsignedSmallInteger('slot_interval_minutes')->default(30);
            $table->unsignedSmallInteger('min_notice_hours')->default(2);
            $table->unsignedSmallInteger('max_days_ahead')->default(14);
            // {mon: [{start: "09:00", end: "18:00"}], ..., sun: []}
            $table->json('working_hours');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_scheduling_configs');
    }
};
