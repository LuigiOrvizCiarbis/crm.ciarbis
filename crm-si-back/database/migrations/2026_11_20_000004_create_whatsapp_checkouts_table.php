<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('whatsapp_flow_session_id')->nullable()->constrained('whatsapp_flow_sessions')->nullOnDelete();
            $table->string('status', 20);
            // {product_id, variant_id|null, quantity} con ids locales.
            $table->json('items');
            $table->json('quote')->nullable();
            // Cart-Token de la Store API (vale 48 h): el cambio de cupón no repite la cotización.
            $table->text('cart_token')->nullable();
            $table->json('shipping_address')->nullable();
            $table->json('shipping_rate')->nullable();
            $table->string('coupon_code', 50)->nullable();
            $table->string('email')->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->unsignedBigInteger('external_order_id')->nullable();
            $table->string('external_order_number', 40)->nullable();
            $table->string('payment_preference_id')->nullable();
            $table->text('payment_url')->nullable();
            $table->timestamp('payment_expires_at')->nullable();
            $table->string('payment_id')->nullable();
            $table->foreignId('payment_link_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();
            // Recordatorio del estado actual (carrito o pago); se limpia al cambiar de estado.
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('shipped_notified_at')->nullable();
            $table->timestamp('payment_failed_notified_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['conversation_id', 'status']);
            $table->index(['tenant_id', 'external_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_checkouts');
    }
};
