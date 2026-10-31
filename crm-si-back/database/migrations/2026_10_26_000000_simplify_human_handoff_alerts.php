<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 1 de derivaciones: la alerta por WhatsApp va al número del canal (sólo
 * coexistence), no a números personales. Se borran las columnas de alta por
 * usuario y se agregan los dos interruptores: por tenant (la IA ofrece la
 * derivación) y por canal (avisar al número del negocio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['whatsapp_notification_phone_normalized']);
            $table->dropColumn([
                'whatsapp_notification_phone',
                'whatsapp_notification_phone_normalized',
                'whatsapp_notification_verified_at',
                'whatsapp_notification_opted_in_at',
            ]);
        });

        Schema::table('ai_configs', function (Blueprint $table) {
            $table->boolean('human_handoff_enabled')->default(true)->after('enabled');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('handoff_whatsapp_alert_enabled')->default(true)->after('handoff_responsible_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('handoff_whatsapp_alert_enabled');
        });

        Schema::table('ai_configs', function (Blueprint $table) {
            $table->dropColumn('human_handoff_enabled');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_notification_phone', 40)->nullable()->after('phone');
            $table->string('whatsapp_notification_phone_normalized', 32)->nullable()->after('whatsapp_notification_phone')->index();
            $table->timestamp('whatsapp_notification_verified_at')->nullable()->after('whatsapp_notification_phone_normalized');
            $table->timestamp('whatsapp_notification_opted_in_at')->nullable()->after('whatsapp_notification_verified_at');
        });
    }
};
