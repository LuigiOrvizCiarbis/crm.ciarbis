<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('woocommerce_configs', function (Blueprint $table) {
            $table->boolean('whatsapp_checkout_enabled')->default(false);
            $table->string('meta_catalog_id', 64)->nullable();
            // product_id | sku: cómo se arma el retailer id del catálogo de Meta.
            $table->string('catalog_retailer_id_format', 20)->default('product_id');
            $table->string('tracking_meta_key', 100)->nullable();
            $table->string('webhook_token', 64)->nullable()->unique();
            // Encriptado (patrón de consumer_key).
            $table->text('webhook_secret')->nullable();
            $table->json('webhook_ids')->nullable();
            $table->foreignId('paid_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
            $table->foreignId('shipped_template_id')->nullable()->constrained('whatsapp_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('woocommerce_configs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_template_id');
            $table->dropConstrainedForeignId('shipped_template_id');
            $table->dropUnique(['webhook_token']);
            $table->dropColumn([
                'whatsapp_checkout_enabled', 'meta_catalog_id', 'catalog_retailer_id_format',
                'tracking_meta_key', 'webhook_token', 'webhook_secret', 'webhook_ids',
            ]);
        });
    }
};
