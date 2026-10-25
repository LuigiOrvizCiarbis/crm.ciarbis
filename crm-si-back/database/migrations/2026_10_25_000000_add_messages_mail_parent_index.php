<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** PostgreSQL cannot create/drop an index concurrently inside a transaction. */
    public $withinTransaction = false;

    /**
     * Eager load de Message::mailAttachments() filtra solo por mail_parent_message_id,
     * sin conversation_id, así que no puede usar el índice compuesto existente y hace
     * seq scan sobre toda la tabla. Índice parcial: casi vacío y cubre el ORDER BY id.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS messages_mail_parent_id_idx ON messages (mail_parent_message_id, id) WHERE mail_parent_message_id IS NOT NULL AND deleted_at IS NULL');

            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->index(['mail_parent_message_id', 'id'], 'messages_mail_parent_id_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS messages_mail_parent_id_idx');

            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_mail_parent_id_idx');
        });
    }
};
