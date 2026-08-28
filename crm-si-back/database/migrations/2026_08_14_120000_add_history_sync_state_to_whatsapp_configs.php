<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado del paso 2 de la sincronización de coexistencia (SMB): el historial de
 * mensajes. Es una llamada separada a POST /smb_app_data con
 * sync_type=history, posterior al sync de contactos (sync_type=smb_app_state_sync),
 * también limitada a una vez por onboarding y a la misma ventana de 24h.
 *
 * Se trackea aparte de contact_sync_* porque son dos permisos/eventos distintos
 * del cliente en Meta: puede aceptar compartir contactos y no historial (o
 * viceversa), y mezclar los estados ocultaría cuál de los dos falló.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table) {
            // pending | syncing | completed | failed | not_applicable
            $table->string('contact_history_sync_status', 32)->nullable()->index();

            // Momento del POST /smb_app_data (sync_type=history) exitoso.
            $table->timestamp('contact_history_sync_requested_at')->nullable();

            // `request_id` que devuelve Meta para este pedido en particular.
            $table->string('contact_history_sync_request_id', 255)->nullable();

            $table->text('contact_history_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table) {
            $table->dropColumn([
                'contact_history_sync_status',
                'contact_history_sync_requested_at',
                'contact_history_sync_request_id',
                'contact_history_sync_error',
            ]);
        });
    }
};
