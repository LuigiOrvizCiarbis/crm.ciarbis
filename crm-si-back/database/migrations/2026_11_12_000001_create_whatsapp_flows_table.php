<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('whatsapp_config_id')->constrained('whatsapp_configs')->cascadeOnDelete();
            $table->string('meta_flow_id', 64);
            $table->string('name', 200);
            $table->string('status', 20)->default('DRAFT');
            $table->json('categories');
            $table->longText('flow_json')->nullable();
            $table->string('json_version', 10)->nullable();
            $table->string('data_api_version', 10)->nullable();
            $table->string('endpoint_uri', 500)->nullable();
            $table->json('validation_errors')->nullable();
            $table->json('field_mappings')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('builder_definition')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_config_id', 'meta_flow_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_flows');
    }
};
