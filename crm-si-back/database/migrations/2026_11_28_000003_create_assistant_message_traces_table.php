<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Vincula cada respuesta del asistente interno con su trace de Langfuse.
        Schema::create('assistant_message_traces', function (Blueprint $table) {
            $table->string('message_id', 36)->primary();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('trace_id', 32);
            $table->string('observation_id', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_message_traces');
    }
};
