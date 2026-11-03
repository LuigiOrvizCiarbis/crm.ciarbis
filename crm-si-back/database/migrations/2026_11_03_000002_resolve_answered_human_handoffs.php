<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las derivaciones anteriores al cierre automático por respuesta humana quedaron
 * activas aunque un vendedor contestó. Se cierran con la hora de esa primera respuesta.
 */
return new class extends Migration
{
    private const FIRST_HUMAN_REPLY = <<<'SQL'
        SELECT MIN(messages.created_at) FROM messages
        WHERE messages.conversation_id = human_handoffs.conversation_id
          AND messages.created_at > human_handoffs.created_at
          AND messages.sender_type = 'user'
          AND messages.direction = 'outbound'
          AND messages.deleted_at IS NULL
        SQL;

    public function up(): void
    {
        DB::table('human_handoffs')
            ->whereIn('status', ['pending', 'acknowledged'])
            ->whereRaw('('.self::FIRST_HUMAN_REPLY.') IS NOT NULL')
            ->update([
                'status' => 'resolved',
                'resolved_at' => DB::raw('('.self::FIRST_HUMAN_REPLY.')'),
                'updated_at' => now(),
            ]);
    }

    /** Corrección de datos: no hay estado anterior que valga la pena restaurar. */
    public function down(): void {}
};
