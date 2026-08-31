<?php

namespace App\Http\Resources;

use App\Enums\BroadcastRecipientStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BroadcastRecipientResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $message = $this->message;
        $status = match (true) {
            $message?->read_at !== null => 'read',
            $message?->delivered_at !== null => 'delivered',
            $this->status === BroadcastRecipientStatus::Failed => 'failed',
            $this->sent_at !== null => 'accepted_unconfirmed',
            in_array($this->status, [BroadcastRecipientStatus::Pending, BroadcastRecipientStatus::Queued], true) => 'pending',
            default => 'pending',
        };
        $latest = $this->interactions->sortByDesc('occurred_at')->first();

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'contact' => ['id' => $this->contact?->id, 'name' => $this->contact?->name, 'phone' => $this->contact?->phone],
            'status' => $status,
            'status_label' => match ($status) {
                'read' => 'Leído', 'delivered' => 'Entregado', 'accepted_unconfirmed' => 'Aceptado, sin confirmación',
                'failed' => 'Fallido', default => 'Pendiente',
            },
            'queued_at' => $this->queued_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'delivered_at' => $message?->delivered_at?->toIso8601String(),
            'read_at' => $message?->read_at?->toIso8601String(),
            'failure' => $this->status === BroadcastRecipientStatus::Failed ? [
                'message' => $this->realFailureMessage(),
                'code' => $this->failure_code,
                'details' => $this->failure_details,
            ] : null,
            'interaction' => $latest ? [
                'type' => $latest->type,
                'value' => $latest->value,
                'content' => $latest->content,
                'occurred_at' => $latest->occurred_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Devuelve el texto original que Meta informó para el fallo.
     *
     * `failure_details` conserva el array `errors[]` del webhook. Se prioriza
     * `error_data.details`, que es el detalle accionable, y se cae a los
     * campos alternativos para tolerar las distintas respuestas de Graph API.
     */
    private function realFailureMessage(): string
    {
        $details = $this->failure_details;
        if (is_array($details)) {
            // El webhook normalmente entrega una lista, pero aceptamos
            // también un único objeto para cubrir respuestas de Graph API.
            $errors = array_is_list($details) ? $details : [$details];
            $messages = collect($errors)
                ->filter(fn ($error): bool => is_array($error))
                ->map(function (array $error): ?string {
                    $value = data_get($error, 'error_data.details')
                        ?: ($error['message'] ?? null)
                        ?: ($error['title'] ?? null);

                    return is_string($value) ? $value : null;
                })
                ->filter(fn (?string $message): bool => trim((string) $message) !== '')
                ->map(fn (string $message): string => trim($message))
                ->values();

            if ($messages->isNotEmpty()) {
                return $messages->implode('; ');
            }
        }

        // Los fallos que ocurren antes del webhook sólo tienen `error`.
        // Quitamos el prefijo que el CRM agrega al serializar errores de Meta,
        // pero conservamos el contenido original restante.
        $raw = trim((string) ($this->error ?? ''));
        $clean = preg_replace('/(^|;\s*)\[\d+\]\s*/', '$1', $raw) ?? $raw;

        return trim($clean) !== ''
            ? trim($clean)
            : 'Meta no pudo entregar este mensaje. Revisá el detalle técnico si necesitás más información.';
    }
}
