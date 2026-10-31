<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcast_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whatsapp_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('status')->default('scheduled');
            $table->json('audience_filters')->default('{}');
            $table->json('components')->default('[]');
            $table->unsignedInteger('audience_count')->default(0);
            $table->decimal('estimated_cost_usd', 12, 2)->default(0);
            $table->decimal('actual_cost_usd', 12, 2)->default(0);
            $table->unsignedInteger('interval_seconds')->default(0);
            $table->timestampTz('scheduled_at');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'scheduled_at']);
        });

        Schema::create('broadcast_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broadcast_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('pending');
            $table->text('error')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->unique(['broadcast_campaign_id', 'conversation_id']);
            $table->index(['broadcast_campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcast_campaigns');
    }
};
