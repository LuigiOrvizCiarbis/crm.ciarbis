<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo con que se creó una plantilla de encabezado de media. Meta solo lo
 * guarda como ejemplo para la revisión y exige el archivo en cada envío: el CRM
 * lo conserva para mandarlo por defecto cuando el envío no trae otro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->foreignId('header_media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('header_media_asset_id');
        });
    }
};
