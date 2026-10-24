<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->index('created_at');
        });

        // Una sola notificación agrupada no leída por usuario/workspace/tipo/grupo:
        // serializa la creación concurrente (ver NotificationService::upsert).
        // Índice parcial: Postgres y SQLite lo soportan; Blueprint no lo expresa.
        DB::statement('CREATE UNIQUE INDEX user_notifications_unread_group_unique
            ON user_notifications (user_id, tenant_id, type, group_key)
            WHERE read_at IS NULL AND group_key IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
