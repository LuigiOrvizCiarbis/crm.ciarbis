<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_configs', function (Blueprint $table) {
            // Información complementaria al system prompt, generada desde el
            // sitio web del tenant y revisada por él antes de guardarla.
            $table->string('website_url', 2048)->nullable()->after('system_prompt');
            $table->text('website_context')->nullable()->after('website_url');
        });
    }

    public function down(): void
    {
        Schema::table('ai_configs', function (Blueprint $table) {
            $table->dropColumn(['website_url', 'website_context']);
        });
    }
};
