<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 48);
            // Agrupa ráfagas (ej. varios mensajes de la misma conversación) en
            // una sola fila no leída que incrementa `count`.
            $table->string('group_key', 120)->nullable();
            $table->json('data');
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'tenant_id', 'read_at']);
            $table->index(['user_id', 'tenant_id', 'group_key', 'read_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
