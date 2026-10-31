<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PostgreSQL cannot create/drop an index concurrently inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_tenant_last_message_id_idx ON conversations (tenant_id, (COALESCE(last_message_at, created_at)) DESC, id DESC)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_tenant_assigned_idx ON conversations (tenant_id, assigned_to)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversations_tenant_channel_idx ON conversations (tenant_id, channel_id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS conversation_user_user_conversation_idx ON conversation_user (user_id, conversation_id)');
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS messages_unread_inbound_conversation_idx ON messages (conversation_id) WHERE direction = 'inbound' AND mail_parent_message_id IS NULL AND read_at IS NULL AND deleted_at IS NULL");

            return;
        }

        Schema::table('conversations', function (Blueprint $table): void {
            $table->index(['tenant_id', 'last_message_at', 'id'], 'conversations_tenant_last_message_id_idx');
            $table->index(['tenant_id', 'assigned_to'], 'conversations_tenant_assigned_idx');
            $table->index(['tenant_id', 'channel_id'], 'conversations_tenant_channel_idx');
        });
        Schema::table('conversation_user', function (Blueprint $table): void {
            $table->index(['user_id', 'conversation_id'], 'conversation_user_user_conversation_idx');
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['conversation_id', 'direction', 'read_at'], 'messages_unread_inbound_conversation_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_tenant_last_message_id_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_tenant_assigned_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversations_tenant_channel_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversation_user_user_conversation_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS messages_unread_inbound_conversation_idx');

            return;
        }

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_tenant_last_message_id_idx');
            $table->dropIndex('conversations_tenant_assigned_idx');
            $table->dropIndex('conversations_tenant_channel_idx');
        });
        Schema::table('conversation_user', function (Blueprint $table): void {
            $table->dropIndex('conversation_user_user_conversation_idx');
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_unread_inbound_conversation_idx');
        });
    }
};
