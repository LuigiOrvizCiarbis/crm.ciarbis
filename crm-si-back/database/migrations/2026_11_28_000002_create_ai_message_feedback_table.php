<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Valoración 👍/👎 de un operador sobre una respuesta de la IA en el
        // chat. Se replica como score en Langfuse.
        Schema::create('ai_message_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->smallInteger('rating');
            $table->timestamps();

            $table->unique(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_message_feedback');
    }
};
