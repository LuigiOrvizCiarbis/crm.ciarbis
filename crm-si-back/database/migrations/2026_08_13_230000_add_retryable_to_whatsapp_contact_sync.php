<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table): void {
            // null conserva el comportamiento de registros creados antes de esta
            // migración; false identifica errores terminales que exigen reconectar.
            $table->boolean('contact_sync_retryable')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table): void {
            $table->dropColumn('contact_sync_retryable');
        });
    }
};
