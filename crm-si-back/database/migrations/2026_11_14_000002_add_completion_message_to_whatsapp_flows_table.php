<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->json('completion_message')->nullable()->after('send_defaults');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_flows', function (Blueprint $table): void {
            $table->dropColumn('completion_message');
        });
    }
};
