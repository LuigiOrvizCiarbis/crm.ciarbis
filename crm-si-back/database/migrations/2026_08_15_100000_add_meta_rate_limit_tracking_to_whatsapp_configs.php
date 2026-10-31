<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking de la cuota de rate limit de Meta para el business token del
 * cliente (usado en smb_app_data), y campos que hacían falta para mostrar el
 * resultado real de un onboarding en /configuracion.
 *
 * meta_app_usage_pct/at: la única forma de ver esta cuota es el header
 * X-App-Usage de cada respuesta de Graph API. El panel de rate limits de la
 * app (MCP, dashboard) mide otro contador —llamadas con el token de la app—
 * que puede estar sano mientras smb_app_data ya está siendo rechazado con
 * (#4) Application request limit reached.
 *
 * contact_sync_error_code: hoy contact_sync_error es texto libre que el front
 * muestra tal cual llega de Meta. Un código tipado permite traducirlo a un
 * mensaje entendible (mismo patrón que AiTestErrorCode en el front, usado
 * para errores de proveedores de IA).
 *
 * contact_history_sync_messages_count: contact_history_sync_status ya existe
 * pero no dice cuántos mensajes trajo. Se cuenta en el momento del create
 * (no el valor bruto que processHistorySync procesa antes del dedupe), para
 * no inflar el número con duplicados que Meta reentrega entre chunks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table) {
            $table->unsignedTinyInteger('meta_app_usage_pct')->nullable();
            $table->timestamp('meta_app_usage_at')->nullable();

            $table->string('contact_sync_error_code', 32)->nullable();

            $table->unsignedInteger('contact_history_sync_messages_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table) {
            $table->dropColumn([
                'meta_app_usage_pct',
                'meta_app_usage_at',
                'contact_sync_error_code',
                'contact_history_sync_messages_count',
            ]);
        });
    }
};
