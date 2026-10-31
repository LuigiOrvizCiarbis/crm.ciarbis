<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_message_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('subject')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('from')->nullable();
            $table->json('to')->nullable();
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->json('reply_to')->nullable();
            $table->json('in_reply_to')->nullable();
            $table->json('references')->nullable();
            $table->boolean('has_remote_images')->default(false);
            $table->timestamps();
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('mail_parent_message_id')
                ->nullable()
                ->after('mail_message_id')
                ->constrained('messages')
                ->cascadeOnDelete();
            $table->index(['conversation_id', 'mail_parent_message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['conversation_id', 'mail_parent_message_id']);
            $table->dropForeign(['mail_parent_message_id']);
            $table->dropColumn('mail_parent_message_id');
        });

        Schema::dropIfExists('mail_message_details');
    }
};
