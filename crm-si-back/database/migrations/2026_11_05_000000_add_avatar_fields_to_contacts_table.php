<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Ruta relativa al disco `public`. Las URLs de foto que entrega Meta
            // vencen, por eso la imagen se descarga y se sirve desde acá.
            $table->string('avatar_path')->nullable();
            // `manual` (subida por un agente) o el canal que la trajo
            // (`facebook`, `instagram`). Una foto manual nunca se pisa desde el canal.
            $table->string('avatar_source', 20)->nullable();
            // Último intento de traer la foto desde el canal, haya o no foto.
            // Evita consultar la Graph API en cada mensaje entrante.
            $table->timestamp('avatar_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'avatar_source', 'avatar_checked_at']);
        });
    }
};
