<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('message_type', 20);
            $table->text('content')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->boolean('voice')->default(false);
            // timestamptz: el front manda ISO con Z y el dispatcher compara en
            // UTC. Ver HasTimezoneAwareDates.
            $table->timestampTz('scheduled_for');
            $table->string('status', 20)->default('scheduled');
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error_reason', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'scheduled_for']);
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
