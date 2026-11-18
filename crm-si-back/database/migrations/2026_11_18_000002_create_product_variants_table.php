<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            // ID de la variación en la tienda (WooCommerce variation_id).
            $table->string('external_id');
            $table->string('sku')->nullable();
            // Combinación que define la variante, p. ej. {"Talle": "M", "Color": "Negro"}.
            // Un valor vacío significa "cualquiera" (atributo sin fijar en Woo).
            $table->json('attribute_values');
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->string('stock_status', 20)->nullable();
            $table->text('image_url')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'external_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
