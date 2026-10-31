<?php

namespace App\Services;

use App\Jobs\SendHumanHandoffNotificationJob;
use App\Models\Conversation;
use App\Models\HandoffNotificationAttempt;
use App\Models\HumanHandoff;
use App\Models\Message;
use App\Models\User;
use App\Services\Notifications\DomainNotifier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HumanHandoffService
{
    /**
     * Orden de los estados que Meta informa para la alerta. `delivered` puede
     * llegar después de `read` (o no llegar nunca): un estado sólo pisa a otro
     * de menor rango.
     */
    private const ALERT_STATUS_RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    public function create(Conversation $conversation, ?Message $trigger = null, string $reason = 'customer_requested_human', ?string $summary = null, string $locale = 'es'): ?HumanHandoff
    {
        return DB::transaction(function () use ($conversation, $trigger, $reason, $summary, $locale) {
            $conversation = Conversation::query()->lockForUpdate()->with('channel')->findOrFail($conversation->id);
            $active = $conversation->humanHandoffs()->whereIn('status', ['pending', 'acknowledged'])->latest()->first();
            if ($active) {
                return $active;
            }

            // Quien ya venía atendiendo tiene el contexto: el responsable del
            // canal sólo decide cuando la conversación no tiene asignado. El
            // dueño del canal es el último recurso para que nunca quede sin
            // nadie que pueda tomarla.
            $assignedTo = $conversation->assigned_to
                ?? $conversation->channel?->handoff_responsible_user_id
                ?? $conversation->channel?->user_id;

            $handoff = HumanHandoff::create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'channel_id' => $conversation->channel_id,
                'assigned_to' => $assignedTo,
                'trigger_message_id' => $trigger?->id,
                'reason' => Str::limit($reason, 120, ''),
                'summary' => Str::limit(preg_replace('/\s+/u', ' ', trim((string) $summary)), 300),
                'customer_locale' => in_array($locale, ['es', 'en'], true) ? $locale : 'es',
                'status' => 'pending',
            ]);

            $conversation->update([
                'assigned_to' => $assignedTo,
                'ai_autoreply_enabled' => false,
            ]);

            SendHumanHandoffNotificationJob::dispatch($handoff->id)->afterCommit();
            app(DomainNotifier::class)->handoffRequested($handoff, $conversation);

            return $handoff;
        });
    }

    /**
     * Cualquiera que pueda escribir en la conversación puede tomarla (la
     * autorización la hace el controller); si no era el asignado, pasa a serlo.
     */
    public function acknowledge(HumanHandoff $handoff, User $user): HumanHandoff
    {
        DB::transaction(function () use ($handoff, $user) {
            $updates = ['assigned_to' => $user->id];
            if ($handoff->status === 'pending') {
                $updates += ['status' => 'acknowledged', 'acknowledged_at' => now()];
            }
            $handoff->update($updates);
            $handoff->conversation()->update(['assigned_to' => $user->id]);
        });

        return $handoff->fresh();
    }

    /**
     * Un mensaje humano (CRM, celular en coexistence, template, programado)
     * cierra la derivación. Los mensajes anteriores a la derivación (p. ej.
     * historial importado) no la cierran.
     */
    public function resolveForHumanMessage(Conversation $conversation, ?CarbonInterface $sentAt = null): void
    {
        $conversation->humanHandoffs()
            ->whereIn('status', ['pending', 'acknowledged'])
            // Los echoes traen el timestamp de Meta en UTC; human_handoffs
            // guarda hora local sin zona. Comparar en la zona de la app.
            ->when($sentAt, fn ($query) => $query->where('created_at', '<=', $sentAt->copy()->setTimezone(config('app.timezone'))))
            ->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    public function cancelActive(Conversation $conversation): void
    {
        $conversation->humanHandoffs()
            ->whereIn('status', ['pending', 'acknowledged'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    }

    /**
     * Aplica un estado de Meta (webhook del número de alertas) al intento de
     * notificación que corresponda.
     *
     * @param  array<string, mixed>  $status
     */
    public function recordAlertStatus(array $status): void
    {
        $attempt = HandoffNotificationAttempt::where('external_id', $status['id'] ?? null)->first();
        if (! $attempt) {
            return;
        }

        $state = (string) ($status['status'] ?? '');
        if ($state === 'failed') {
            // Un fallo tardío no borra una entrega ya confirmada.
            if (! in_array($attempt->status, ['delivered', 'read'], true)) {
                $attempt->update([
                    'status' => 'failed',
                    'error' => data_get($status, 'errors.0.title') ?: data_get($status, 'errors.0.message'),
                ]);
            }

            return;
        }

        $rank = self::ALERT_STATUS_RANK[$state] ?? null;
        if ($rank === null) {
            return;
        }

        $updates = [];
        if ($state === 'delivered' || $state === 'read') {
            $updates['delivered_at'] = $attempt->delivered_at ?? now();
        }
        if ($state === 'read') {
            $updates['read_at'] = $attempt->read_at ?? now();
        }
        // `failed` vale como `sent`: un `sent` tardío no lo tapa, pero una
        // entrega confirmada sí.
        $currentRank = $attempt->status === 'failed' ? self::ALERT_STATUS_RANK['sent'] : (self::ALERT_STATUS_RANK[$attempt->status] ?? -1);
        if ($rank > $currentRank) {
            $updates['status'] = $state;
        }

        if ($updates !== []) {
            $attempt->update($updates);
        }
    }
}
