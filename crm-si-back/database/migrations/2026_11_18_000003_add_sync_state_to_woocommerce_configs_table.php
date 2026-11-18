<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_configs', function (Blueprint $table) {
            $table->string('sync_status', 20)->nullable()->after('last_synced_at');
            // Identifica la corrida en curso: los jobs de una corrida reemplazada se descartan.
            $table->uuid('sync_run_id')->nullable()->after('sync_status');
            $table->timestamp('sync_started_at')->nullable()->after('sync_run_id');
            // Último avance de la corrida; sin avance por un rato se la considera trabada.
            $table->timestamp('sync_heartbeat_at')->nullable()->after('sync_started_at');
            $table->timestamp('sync_finished_at')->nullable()->after('sync_heartbeat_at');
            $table->json('sync_result')->nullable()->after('sync_finished_at');
            $table->text('sync_error')->nullable()->after('sync_result');
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_configs', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'sync_run_id', 'sync_started_at', 'sync_heartbeat_at', 'sync_finished_at', 'sync_result', 'sync_error']);
        });
    }
};
