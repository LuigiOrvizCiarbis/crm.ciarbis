<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sin tenant_id a propósito: es caché de contenido público (el HTML
        // de un link ajeno), deduplicado por url_hash entre todos los tenants.
        Schema::create('link_previews', function (Blueprint $table): void {
            $table->id();
            $table->string('url_hash', 64)->unique();
            $table->text('url');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('site_name')->nullable();
            $table->string('image_path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_previews');
    }
};
