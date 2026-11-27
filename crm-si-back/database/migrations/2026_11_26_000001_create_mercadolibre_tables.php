<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuenta de vendedor de Mercado Libre conectada por OAuth, una por tenant.
        Schema::create('mercadolibre_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('ml_user_id');
            $table->string('nickname')->nullable();
            $table->string('site_id', 5);
            $table->string('currency_id', 5);
            // Vendedor en el modelo "User Products": publica con family_name en vez de title.
            $table->boolean('user_products')->default(false);
            // Encriptados. El refresh token es de un solo uso: cada refresh lo reemplaza.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->boolean('sync_enabled')->default(false);
            $table->string('listing_type_id', 20)->default('gold_special');
            $table->string('condition', 10)->default('new');
            $table->unsignedInteger('default_quantity')->default(1);
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamps();
        });

        // Ajustes de publicación de un producto: categoría y atributos de ML.
        Schema::create('mercadolibre_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('category_id', 30)->nullable();
            $table->string('category_name')->nullable();
            // La categoría salió del predictor de ML, no la eligió nadie.
            $table->boolean('category_predicted')->default(false);
            $table->json('attribute_values');
            $table->timestamps();
        });

        // Un ítem de ML por unidad vendible (el producto o cada variante). Sin FK
        // a productos/variantes a propósito: al borrarlos, el sync necesita la
        // fila para cerrar el ítem en ML antes de olvidarla.
        Schema::create('mercadolibre_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id')->index();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('ml_item_id', 30)->nullable();
            $table->string('ml_user_product_id', 30)->nullable();
            $table->string('ml_status', 30)->nullable();
            $table->string('permalink', 500)->nullable();
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->json('missing')->nullable();
            // Hash de lo último que se mandó: si no cambió nada no se llama a ML.
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'product_variant_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercadolibre_listings');
        Schema::dropIfExists('mercadolibre_products');
        Schema::dropIfExists('mercadolibre_connections');
    }
};
