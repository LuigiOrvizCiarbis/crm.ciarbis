<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // Reactor polimórfico manual (NO morphs): no hay morph map registrado
            // en el proyecto y `messages.sender_type` ya usa este mismo par de
            // columnas con el enum SenderType ('contact'/'user'). Se reutiliza
            // ese vocabulario en vez de crear uno nuevo.
            $table->string('reactor_type', 20);
            $table->unsignedBigInteger('reactor_id');

            // 32 y no 8: emojis compuestos (ZWJ, banderas, selectores de
            // variación) superan fácil los 8 bytes.
            $table->string('emoji', 32);

            // wamid de la reacción saliente (lo devuelve Meta) o del evento
            // entrante (messages[0].id). Nullable: sirve de dedupe de webhooks.
            $table->string('external_id')->nullable();

            // UTC explícito. Gotcha del proyecto: `timestamp` sin tz guarda hora
            // ARG local (como messages.created_at); esta tabla es nueva y sus
            // timestamps sólo se comparan entre sí o contra el timestamp UTC de
            // Meta, así que va todo timestampTz.
            $table->timestampTz('reacted_at');
            $table->timestampsTz();

            // Una reacción por reactor por mensaje: reaccionar de nuevo es un
            // UPDATE, no un INSERT.
            $table->unique(['message_id', 'reactor_type', 'reactor_id'], 'message_reactions_unique_reactor');
            $table->unique('external_id', 'message_reactions_external_id_unique');

            $table->index(['conversation_id', 'reacted_at']);
            $table->index(['tenant_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
