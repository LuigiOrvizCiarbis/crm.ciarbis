<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('cascade');
            $table->foreignId('media_asset_id')->constrained('media_assets')->onDelete('cascade');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // queued → processing → completed|failed, y completed → confirmed
            // cuando el usuario aplica los datos al contacto.
            $table->string('status', 20)->default('queued');

            // Campos extraídos, tal como los devolvió el modelo: {key: value}.
            $table->json('result')->nullable();

            // Campos vigentes al momento de extraer (key => tipo). Si el tenant
            // borra un ContactField mientras el usuario revisa, la confirmación
            // descarta esa clave: ValidContactCustomData sólo itera los campos
            // que existen y no rechaza claves desconocidas, así que sin esto la
            // clave huérfana entraría a custom_data sin validación.
            $table->json('fields_snapshot')->nullable();

            // Texto extraído del PDF. Se guarda para mostrarlo junto a los
            // campos en la revisión: el usuario compara contra el documento.
            $table->longText('document_text')->nullable();

            // full | partial. Parcial = algunas páginas no tenían texto
            // extraíble; la UI debe decir "no está en el texto extraído" en vez
            // de afirmar que el dato no está en el contrato.
            $table->string('text_coverage', 20)->nullable();
            $table->json('pages_without_text')->nullable();

            // Lease del job. Un worker que muere (OOM, deploy) deja la fila en
            // processing para siempre: el CAS de queued→processing impide que
            // otro job la tome. El watchdog usa este timestamp para recuperarla.
            $table->timestamp('processing_started_at')->nullable();

            // Versión del contacto al momento de extraer, para detectar
            // ediciones concurrentes al confirmar.
            $table->unsignedBigInteger('contact_lock_version')->nullable();

            $table->string('error_code', 40)->nullable();
            $table->text('error_message')->nullable();

            // generate() descarta el usage; una extracción cuesta bastante más
            // por request, así que conviene poder medirla.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'contact_id', 'created_at']);
            // Para el watchdog: barre processing viejos sin escanear la tabla.
            $table->index(['status', 'processing_started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_extractions');
    }
};
