<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained()->nullOnDelete();

            // Correlación asíncrona: Meta no devuelve group_id en la respuesta
            // del POST, sólo llega por el webhook group_lifecycle_update.
            $table->string('request_id')->nullable()->index();
            $table->string('group_id')->nullable()->unique();

            $table->string('subject', 128);
            $table->text('description')->nullable();
            $table->string('join_approval_mode')->default('approval_required');
            $table->string('invite_link')->nullable();

            $table->string('status')->default('pending');
            $table->boolean('suspended')->default(false);
            $table->unsignedTinyInteger('total_participant_count')->default(0);
            $table->string('profile_picture_url')->nullable();

            $table->timestampTz('creation_timestamp')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->unique(['channel_id', 'request_id']);
        });

        Schema::create('whatsapp_group_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('whatsapp_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();

            $table->string('wa_id')->nullable();
            $table->string('user_id_bsuid')->nullable();
            $table->string('parent_user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('display_name')->nullable();

            $table->string('role')->default('participant');
            $table->string('status')->default('active');

            $table->string('join_request_id')->nullable()->index();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->string('removed_by')->nullable();

            $table->foreignId('invited_message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestampsTz();

            $table->unique(['whatsapp_group_id', 'wa_id']);
            $table->index(['whatsapp_group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_group_participants');
        Schema::dropIfExists('whatsapp_groups');
    }
};
