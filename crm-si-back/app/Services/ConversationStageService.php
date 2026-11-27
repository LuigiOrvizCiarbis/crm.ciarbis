<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Etapa del chat. Vive en el pipeline predeterminado y, si el chat tiene una
 * oportunidad ahí, la sigue: moverla es la forma de cambiarla.
 */
class ConversationStageService
{
    /**
     * Fija la etapa de un chat sin oportunidad en el pipeline predeterminado.
     * Sin oportunidad el lead no aparece en el tablero: se crea una que desde
     * ahí maneja la etapa del chat. Un grupo no tiene contact_id (NOT NULL en
     * opportunities), así que sólo cambia la etapa.
     */
    public function assign(Conversation $conversation, PipelineStage $stage, ?float $value): void
    {
        DB::transaction(function () use ($conversation, $stage, $value): void {
            $conversation->update(['pipeline_stage_id' => $stage->id]);

            if ($conversation->isGroup()) {
                return;
            }

            Opportunity::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $conversation->contact_id,
                'pipeline_id' => $stage->pipeline_id,
                'pipeline_stage_id' => $stage->id,
                'title' => 'Oportunidad - '.($conversation->contact?->name ?? 'Sin nombre'),
                'status' => 'open',
                'source_type' => 'conversation',
                'last_activity_at' => now(),
                'value' => $value,
            ]);
        });
    }

    /**
     * Cambio de etapa en lote desde /chats: cada chat mueve su oportunidad del
     * pipeline predeterminado o, si no tiene, toma la etapa con una nueva. Lo
     * permitido se aplica aunque otro chat falle.
     *
     * @param  list<int>  $conversationIds
     * @param  float|null  $value  Monto de la venta; sólo se carga donde la oportunidad no tiene uno.
     * @return array{updated: int, failed: int, failures: list<array{id: int, reason: string}>}
     */
    public function bulkApply(User $user, array $conversationIds, PipelineStage $stage, ?float $value): array
    {
        $conversations = Conversation::query()->whereIn('id', $conversationIds)->get()->keyBy('id');
        $updated = 0;
        $failures = [];

        foreach ($conversationIds as $id) {
            $conversation = $conversations->get($id);
            $reason = $conversation !== null && $user->can('changeStage', $conversation)
                ? $this->placeConversationSafely($user, $conversation, $stage, $value)
                : 'No tenés permiso para cambiar la etapa de este chat.';

            if ($reason === null) {
                $updated++;
            } else {
                $failures[] = ['id' => $id, 'reason' => $reason];
            }
        }

        return [
            'updated' => $updated,
            'failed' => count($failures),
            'failures' => $failures,
        ];
    }

    /** @return string|null Motivo del fallo, o null si el chat quedó en la etapa. */
    private function placeConversationSafely(User $user, Conversation $conversation, PipelineStage $stage, ?float $value): ?string
    {
        try {
            return $this->placeConversation($user, $conversation, $stage, $value);
        } catch (\Throwable $e) {
            report($e);

            return 'No se pudo cambiar la etapa de este chat.';
        }
    }

    private function placeConversation(User $user, Conversation $conversation, PipelineStage $stage, ?float $value): ?string
    {
        $opportunity = Opportunity::where('conversation_id', $conversation->id)
            ->inDefaultPipeline()
            ->primaryFirst()
            ->first();

        if ($opportunity === null) {
            $this->assign($conversation, $stage, $value);

            return null;
        }

        if (! $user->can('changeStage', $opportunity)) {
            return 'No tenés permiso para mover la oportunidad de este chat.';
        }

        // Opportunity::saved lleva la etapa al chat.
        $opportunity->moveToStage($stage, $value);

        return null;
    }
}
