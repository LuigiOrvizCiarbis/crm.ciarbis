<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->json('send_defaults')->nullable()->after('builder_definition');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->dropColumn('send_defaults');
        });
    }
};
