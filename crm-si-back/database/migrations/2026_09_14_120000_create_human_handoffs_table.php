<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('human_handoffs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('trigger_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->string('reason', 120)->nullable();
            $table->string('summary', 500)->nullable();
            $table->string('customer_locale', 5)->default('es');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'conversation_id', 'status']);
        });

        Schema::create('handoff_notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('human_handoff_id')->constrained()->cascadeOnDelete();
            $table->string('destination_type', 16);
            $table->string('phone', 40);
            $table->string('status', 16)->default('queued')->index();
            $table->string('external_id')->nullable()->unique();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->unique(['human_handoff_id', 'destination_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoff_notification_attempts');
        Schema::dropIfExists('human_handoffs');
    }
};
