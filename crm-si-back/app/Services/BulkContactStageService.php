<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\User;

/**
 * Cambio de etapa en lote desde /contactos. La etapa de un contacto es la de
 * su oportunidad, así que cada contacto mueve la suya en el embudo de la etapa
 * elegida (la abierta movida más recientemente; si no hay, la última) o, si no
 * tiene ninguna ahí, suma una nueva: lo mismo que el selector de la fila.
 */
class BulkContactStageService
{
    /**
     * @param  list<int>  $contactIds
     * @param  float|null  $value  Monto de la venta; sólo se carga donde la oportunidad no tiene uno.
     * @return array{updated: int, failed: int, failures: list<array{id: int, reason: string}>}
     */
    public function apply(User $user, array $contactIds, PipelineStage $stage, ?float $value): array
    {
        $contacts = Contact::query()->whereIn('id', $contactIds)->get()->keyBy('id');
        $updated = 0;
        $failures = [];

        foreach ($contactIds as $id) {
            $contact = $contacts->get($id);
            $reason = $contact !== null && $user->can('view', $contact)
                ? $this->placeContactSafely($user, $contact, $stage, $value)
                : 'No tenés permiso para ver este contacto.';

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

    /** @return string|null Motivo del fallo, o null si el contacto quedó en la etapa. */
    private function placeContactSafely(User $user, Contact $contact, PipelineStage $stage, ?float $value): ?string
    {
        try {
            return $this->placeContact($user, $contact, $stage, $value);
        } catch (\Throwable $e) {
            report($e);

            return 'No se pudo cambiar la etapa de este contacto.';
        }
    }

    private function placeContact(User $user, Contact $contact, PipelineStage $stage, ?float $value): ?string
    {
        $opportunity = $contact->opportunities()
            ->listedFor($user)
            ->where('pipeline_id', $stage->pipeline_id)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $opportunity
            ? $this->moveOpportunity($user, $opportunity, $stage, $value)
            : $this->createOpportunity($user, $contact, $stage, $value);
    }

    private function moveOpportunity(User $user, Opportunity $opportunity, PipelineStage $stage, ?float $value): ?string
    {
        if (! $user->can('changeStage', $opportunity)) {
            return 'No tenés permiso para mover la oportunidad de este contacto.';
        }

        $attributes = ['pipeline_stage_id' => $stage->id];
        if ($value !== null && ! ((float) $opportunity->value > 0)) {
            $attributes['value'] = $value;
        }

        $opportunity->update($attributes);

        return null;
    }

    private function createOpportunity(User $user, Contact $contact, PipelineStage $stage, ?float $value): ?string
    {
        if (! $user->can('create', Opportunity::class)) {
            return 'No tenés permiso para crear oportunidades.';
        }

        $conversation = $contact->conversations()->latest('last_message_at')->first(['id', 'last_message_at']);

        Opportunity::create([
            'contact_id' => $contact->id,
            'conversation_id' => $conversation?->id,
            'pipeline_id' => $stage->pipeline_id,
            'pipeline_stage_id' => $stage->id,
            'title' => 'Oportunidad - '.$contact->name,
            'status' => 'open',
            'source_type' => $conversation ? 'conversation' : 'manual',
            'value' => $value,
            'last_activity_at' => $conversation?->last_message_at ?? now(),
        ]);

        return null;
    }
}
