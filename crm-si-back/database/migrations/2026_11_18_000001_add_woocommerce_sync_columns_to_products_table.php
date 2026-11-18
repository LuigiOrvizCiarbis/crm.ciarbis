<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Tipo del producto en la tienda externa (WooCommerce: simple, variable,
            // grouped, external, subscription…). Define si se puede vender por chat.
            $table->string('external_type', 40)->nullable()->after('external_id');
            // false para productos virtuales: la venta no pide envío.
            $table->boolean('requires_shipping')->default(true)->after('external_type');
            // Corrida de sync que vio el producto por última vez; los que no
            // aparecen en una corrida completa se desactivan.
            $table->uuid('external_sync_run_id')->nullable()->after('requires_shipping');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['external_type', 'requires_shipping', 'external_sync_run_id']);
        });
    }
};
