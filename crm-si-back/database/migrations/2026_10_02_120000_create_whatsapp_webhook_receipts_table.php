<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('dedupe_key', 64)->unique();
            $table->string('queue_name', 32);
            $table->json('payload');
            $table->string('status', 16)->default('pending')->index();
            $table->unsignedInteger('change_index')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_receipts');
    }
};
