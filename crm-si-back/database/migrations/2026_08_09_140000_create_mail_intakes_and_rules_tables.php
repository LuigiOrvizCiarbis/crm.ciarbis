<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_intakes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_config_id')->constrained('mail_configs')->cascadeOnDelete();
            $table->foreignId('accepted_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('external_id')->unique();
            $table->string('status', 16)->index();
            $table->string('classification_reason', 64)->index();
            $table->string('from_email')->index();
            $table->string('from_name')->nullable();
            $table->string('mail_message_id')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->json('reply_to')->nullable();
            $table->json('in_reply_to')->nullable();
            $table->json('references')->nullable();
            $table->json('attachments')->nullable();
            $table->boolean('has_remote_images')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['channel_id', 'status', 'received_at']);
        });

        Schema::create('mail_channel_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8); // allow | block
            $table->string('value_type', 8); // email | domain
            $table->string('value');
            $table->timestamps();
            $table->unique(['channel_id', 'type', 'value_type', 'value'], 'mail_channel_rules_unique');
            $table->index(['channel_id', 'value_type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_channel_rules');
        Schema::dropIfExists('mail_intakes');
    }
};
