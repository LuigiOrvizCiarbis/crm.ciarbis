<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->unsignedTinyInteger('results_tracking_version')->nullable()->after('completed_at');
        });

        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->string('failure_code')->nullable()->after('error');
            $table->json('failure_details')->nullable()->after('failure_code');
        });

        Schema::create('message_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broadcast_recipient_id')->nullable()->constrained('broadcast_recipients')->cascadeOnDelete();
            $table->foreignId('target_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('value')->nullable();
            $table->text('content')->nullable();
            $table->json('payload')->nullable();
            $table->string('deduplication_key')->unique();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->index(['broadcast_recipient_id', 'occurred_at']);
            $table->index(['target_message_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_interactions');
        Schema::table('broadcast_recipients', function (Blueprint $table): void {
            $table->dropColumn(['failure_code', 'failure_details']);
        });
        Schema::table('broadcast_campaigns', function (Blueprint $table): void {
            $table->dropColumn('results_tracking_version');
        });
    }
};
