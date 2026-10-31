<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de la sincronización de contactos de coexistencia (SMB).
 *
 * Meta solo permite disparar el sync una vez por onboarding y da una ventana de
 * 24h. Hasta ahora lo disparábamos a ciegas: un 2xx del POST /smb_app_data se
 * logueaba como "sincronización iniciada", pero los contactos llegan después por
 * el webhook `smb_app_state_sync`. Si el webhook no llegaba, se perdía en
 * silencio y nadie se enteraba hasta que el cliente reclamaba.
 *
 * Persistimos el estado para poder verificar, reintentar dentro de la ventana y
 * mostrarle al usuario si sus contactos llegaron o no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table) {
            // pending | syncing | completed | failed | not_applicable
            $table->string('contact_sync_status', 32)->nullable()->index();

            // Momento del POST /smb_app_data exitoso: ancla de la ventana de 24h.
            $table->timestamp('contact_sync_requested_at')->nullable();

            // Primer webhook smb_app_state_sync recibido: prueba de que Meta respondió.
            $table->timestamp('contact_sync_first_webhook_at')->nullable();

            // Último webhook recibido, para saber si todavía están llegando lotes.
            $table->timestamp('contact_sync_last_webhook_at')->nullable();

            // Contactos efectivamente creados/actualizados por el sync.
            $table->unsignedInteger('contact_sync_contacts_count')->default(0);

            // `request_id` que devuelve Meta: es el identificador que pide soporte
            // de Meta para rastrear un sync que no llegó.
            $table->string('contact_sync_request_id', 255)->nullable();

            $table->text('contact_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table) {
            $table->dropColumn([
                'contact_sync_status',
                'contact_sync_requested_at',
                'contact_sync_first_webhook_at',
                'contact_sync_last_webhook_at',
                'contact_sync_contacts_count',
                'contact_sync_request_id',
                'contact_sync_error',
            ]);
        });
    }
};
