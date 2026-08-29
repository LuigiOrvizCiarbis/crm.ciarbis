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
                'message' => $this->friendlyFailureMessage(),
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

    private function friendlyFailureMessage(): string
    {
        $code = (string) ($this->failure_code ?? '');
        $raw = strtolower((string) ($this->error ?? ''));
        return match (true) {
            $code === '131026' || str_contains($raw, 'undeliverable') => 'El número no está disponible en WhatsApp o no puede recibir este mensaje.',
            $code === '131047' || str_contains($raw, '24 hour') => 'El contacto no respondió dentro de la ventana permitida para este tipo de mensaje.',
            $code === '131049' || str_contains($raw, 'marketing') => 'Meta decidió no entregar este mensaje por sus políticas de mensajería.',
            str_contains($raw, 'rate limit') || str_contains($raw, 'limit') => 'Se alcanzó temporalmente el límite de envíos de WhatsApp.',
            default => 'Meta no pudo entregar este mensaje. Revisá el detalle técnico si necesitás más información.',
        };
    }
}
