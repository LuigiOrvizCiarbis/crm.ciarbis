<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table): void {
            $table->text('flows_private_key')->nullable();
            $table->text('flows_public_key')->nullable();
            $table->timestamp('flows_public_key_uploaded_at')->nullable();
            $table->string('flows_endpoint_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_configs', function (Blueprint $table): void {
            $table->dropUnique(['flows_endpoint_token']);
            $table->dropColumn([
                'flows_private_key',
                'flows_public_key',
                'flows_public_key_uploaded_at',
                'flows_endpoint_token',
            ]);
        });
    }
};
