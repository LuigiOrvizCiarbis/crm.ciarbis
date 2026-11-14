<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            // Lista de {button_index, tag_id}: qué etiqueta recibe el contacto
            // según el botón de respuesta rápida que toque.
            $table->json('reply_tags')->nullable()->after('components');
        });
    }

    public function down(): void
    {
        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->dropColumn('reply_tags');
        });
    }
};
