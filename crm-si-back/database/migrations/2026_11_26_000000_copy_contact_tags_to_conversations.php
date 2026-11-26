<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Los chats muestran y filtran las etiquetas de la conversación. Hasta ahora
 * etiquetar desde Contactos no las copiaba a las conversaciones del contacto;
 * esta migración copia las que ya existían. Es idempotente: solo inserta las
 * asignaciones que faltan.
 *
 * Los tipos morph quedan congelados acá para no depender de los modelos.
 */
return new class extends Migration
{
    private const CONTACT_TYPE = 'App\\Models\\Contact';

    private const CONVERSATION_TYPE = 'App\\Models\\Conversation';

    public function up(): void
    {
        $missingAssignments = DB::table('taggables as contact_tag')
            ->join('conversations', function ($join) {
                $join->on('conversations.contact_id', '=', 'contact_tag.taggable_id')
                    ->on('conversations.tenant_id', '=', 'contact_tag.tenant_id');
            })
            ->where('contact_tag.taggable_type', self::CONTACT_TYPE)
            ->whereNotExists(fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('taggables as conversation_tag')
                ->whereColumn('conversation_tag.tag_id', 'contact_tag.tag_id')
                ->where('conversation_tag.taggable_type', self::CONVERSATION_TYPE)
                ->whereColumn('conversation_tag.taggable_id', 'conversations.id'))
            ->select(['contact_tag.tenant_id', 'contact_tag.tag_id'])
            ->selectRaw('CAST(? AS VARCHAR(255))', [self::CONVERSATION_TYPE])
            ->addSelect([
                'conversations.id',
                'contact_tag.assigned_by',
                'contact_tag.created_at',
                'contact_tag.updated_at',
            ]);

        DB::table('taggables')->insertUsing(
            ['tenant_id', 'tag_id', 'taggable_type', 'taggable_id', 'assigned_by', 'created_at', 'updated_at'],
            $missingAssignments,
        );
    }

    /** Las copias no se distinguen de las asignadas a mano en el chat. */
    public function down(): void {}
};
