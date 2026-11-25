<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->string('system_key', 40)->nullable()->after('name');
            $table->unique(['whatsapp_config_id', 'system_key']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->dropUnique(['whatsapp_config_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
