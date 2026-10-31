<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // conversation_id pasa a ser un RESULTADO del envío, no un insumo: la
        // mayoría de los destinatarios no tiene conversación al crear la
        // campaña (se resuelve en el job, ver SendBroadcastMessageJob).
        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->foreignId('conversation_id')->nullable()->change();
        });

        if (DB::getDriverName() === 'pgsql') {
            // Postgres truncó el nombre original a 63 chars: termina en
            // "_uniq", no en "_unique". dropUnique(['broadcast_campaign_id',
            // 'conversation_id']) infiere el nombre largo y falla — hay que
            // pasar el string real. Verificado con \d en dev y en producción.
            DB::statement('ALTER TABLE broadcast_recipients DROP CONSTRAINT broadcast_recipients_broadcast_campaign_id_conversation_id_uniq');
        } else {
            Schema::table('broadcast_recipients', function (Blueprint $table): void {
                $table->dropUnique(['broadcast_campaign_id', 'conversation_id']);
            });
        }

        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            // El unique anterior ya no protegía nada (los NULL de Postgres no
            // colisionan entre sí). contact_id es el invariante correcto:
            // impide que un contacto con conversación en dos canales reciba
            // dos mensajes de la misma campaña.
            $table->unique(['broadcast_campaign_id', 'contact_id']);

            // Número al que realmente se le envió, para auditar la
            // deduplicación después. Las campañas históricas quedan NULL:
            // en su momento no se dedupeó por teléfono, y no corresponde
            // inventar el dato retroactivamente.
            $table->string('phone_normalized', 32)->nullable()->after('contact_id');
        });

        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('duplicate_phone_count')->default(0)->after('audience_count');
            $table->unsignedInteger('without_consent_count')->default(0)->after('duplicate_phone_count');
            $table->foreignId('consent_warning_accepted_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestampTz('consent_warning_accepted_at')->nullable()->after('consent_warning_accepted_by');
        });

        // conversations NO tiene unique en (tenant_id, contact_id, channel_id):
        // sin él, el firstOrCreate del job de difusión no es atómico y dos
        // jobs concurrentes para el mismo par (contacto, canal) duplican la
        // conversación. El problema ya existe hoy en producción (verificados
        // 8 pares duplicados con mensajes repartidos en ambas copias), así que
        // un unique normal fallaría al crearse. Se limita a las filas nuevas
        // con un índice parcial (solo soportado en Postgres); los duplicados
        // existentes quedan para una limpieza aparte.
        if (DB::getDriverName() === 'pgsql') {
            $maxConversationId = (int) (DB::table('conversations')->max('id') ?? 0);
            DB::statement(<<<SQL
                CREATE UNIQUE INDEX conversations_tenant_contact_channel_uniq
                ON conversations (tenant_id, contact_id, channel_id)
                WHERE id > {$maxConversationId}
            SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS conversations_tenant_contact_channel_uniq');
        }

        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('consent_warning_accepted_by');
            $table->dropColumn(['consent_warning_accepted_at', 'without_consent_count', 'duplicate_phone_count']);
        });

        // Perder datos es inevitable acá: los recipients sin conversación no
        // tienen forma de recuperar un conversation_id para satisfacer el
        // NOT NULL. Se eliminan en vez de dejar el rollback roto a mitad.
        DB::table('broadcast_recipients')->whereNull('conversation_id')->delete();

        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->dropColumn('phone_normalized');
            $table->dropUnique(['broadcast_campaign_id', 'contact_id']);
        });

        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->foreignId('conversation_id')->nullable(false)->change();
        });

        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->unique(['broadcast_campaign_id', 'conversation_id']);
        });
    }
};
