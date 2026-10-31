<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('external_id')->unique();
            $table->string('parent_external_id')->nullable()->index();
            $table->string('author_external_id')->index();
            $table->string('author_username')->nullable();
            $table->text('text')->nullable();
            $table->string('media_id')->nullable()->index();
            $table->string('media_product_type')->nullable();
            $table->string('ad_id')->nullable()->index();
            $table->string('ad_title')->nullable();
            $table->enum('status', ['new', 'in_progress', 'resolved'])->default('new');
            $table->enum('visibility', ['visible', 'hidden', 'deleted'])->default('visible');
            $table->timestamp('commented_at')->nullable();
            $table->timestamp('private_reply_deadline')->nullable();
            $table->timestamp('private_replied_at')->nullable();
            $table->string('private_reply_external_id')->nullable();
            $table->timestamp('last_action_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_comments');
    }
};
