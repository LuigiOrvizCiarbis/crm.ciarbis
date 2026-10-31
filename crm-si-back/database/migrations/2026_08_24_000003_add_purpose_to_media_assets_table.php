<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ata un MediaAsset a su propósito y, cuando aplica, al contacto.
 *
 * El endpoint genérico POST /media-assets sirve para adjuntos de automations y
 * está gateado por automations.manage. Los documentos a extraer se suben por un
 * endpoint propio del contacto, con su propia autorización: sin estas columnas
 * no habría forma de distinguir unos de otros ni de limpiar los que quedaron
 * huérfanos porque el usuario abandonó el diálogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            // 'library' = el espacio de archivos de automations (comportamiento
            // previo); 'extraction' = documento subido para extraer datos.
            $table->string('purpose', 20)->default('library');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            // Para el barrido de huérfanos: assets de extracción sin extracción
            // asociada pasado el TTL.
            $table->index(['tenant_id', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'purpose', 'created_at']);
            $table->dropConstrainedForeignId('contact_id');
            $table->dropColumn('purpose');
        });
    }
};
