<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('platform');
            $table->string('source_type');
            $table->string('ad_id')->nullable()->index();
            $table->string('ref')->nullable();
            $table->text('headline')->nullable();
            $table->text('body')->nullable();
            $table->text('source_url')->nullable();
            $table->string('media_type')->nullable();
            $table->text('media_url')->nullable();
            $table->string('post_id')->nullable();
            $table->string('ctwa_clid')->nullable();
            $table->json('raw')->nullable();
            $table->string('dedupe_key')->unique();
            $table->timestamp('referred_at');
            $table->timestamps();
            $table->index(['tenant_id', 'ad_id']);
            $table->index(['contact_id', 'referred_at']);
            $table->index(['conversation_id', 'referred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_referrals');
    }
};
