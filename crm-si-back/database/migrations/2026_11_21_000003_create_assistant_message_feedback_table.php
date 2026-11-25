<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Valoración 👍/👎 de cada respuesta del asistente interno. message_id
        // apunta a agent_conversation_messages (UUID del Laravel AI SDK).
        Schema::create('assistant_message_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('message_id', 36);
            $table->smallInteger('rating');
            $table->timestamps();

            $table->unique(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_message_feedback');
    }
};
