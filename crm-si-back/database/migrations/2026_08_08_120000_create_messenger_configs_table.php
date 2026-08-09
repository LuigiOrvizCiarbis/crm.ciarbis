<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messenger_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            // PAGE_ID de Facebook. Es a la vez el {page_id} del Send API, el
            // entry.id del webhook y la clave de resolución del canal.
            //
            // Unique (a diferencia del page_id indexado de instagram_configs):
            // en Messenger la página ES la identidad del canal, así que dos
            // configs para la misma página harían el ruteo del webhook no
            // determinístico.
            $table->string('page_id')->unique();
            $table->string('page_name')->nullable();
            // Page access token long-lived (derivado del user token extendido).
            // No expira periódicamente pero puede invalidarse. Encriptado con Crypt.
            $table->longText('page_access_token');
            $table->boolean('ai_autoreply_default')->default(false);
            $table->timestamps();
        });

        // No hay webhook_object_id (que instagram_configs sí tiene): en Messenger
        // entry.id es siempre el PAGE_ID, así que no hace falta la columna
        // flexible ni su backfill — que es justamente el mecanismo que corrompía
        // la resolución cuando dos canales compartían una página.

        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('messenger_config_id')
                ->nullable()
                ->after('instagram_config_id')
                ->constrained('messenger_configs')
                ->nullOnDelete();
        });

        // contacts no necesita cambios: el índice único parcial
        // (tenant_id, source, external_id) WHERE external_id IS NOT NULL creado
        // por la migración de Instagram ya cubre source='facebook'.
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['messenger_config_id']);
            $table->dropColumn('messenger_config_id');
        });

        Schema::dropIfExists('messenger_configs');
    }
};
