<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\HumanHandoff;
use App\Models\Message;
use App\Models\User;
use App\Jobs\SendHumanHandoffNotificationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HumanHandoffService
{
    public function subscribeToWhatsAppNotifications(User $user, string $phone): void
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '549')) {
            $digits = '54'.substr($digits, 3);
        }
        if (! preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
            throw new \InvalidArgumentException('Ingresá un número de WhatsApp con código de país.');
        }

        $user->forceFill([
            'whatsapp_notification_phone' => trim($phone),
            'whatsapp_notification_phone_normalized' => $digits,
            'whatsapp_notification_verified_at' => null,
            'whatsapp_notification_opted_in_at' => now(),
        ])->save();
    }

    public function create(Conversation $conversation, ?Message $trigger = null, string $reason = 'customer_requested_human', ?string $summary = null, string $locale = 'es'): ?HumanHandoff
    {
        return DB::transaction(function () use ($conversation, $trigger, $reason, $summary, $locale) {
            $conversation = Conversation::query()->lockForUpdate()->with(['channel.handoffResponsible', 'channel.whatsappConfig'])->findOrFail($conversation->id);
            $active = $conversation->humanHandoffs()->whereIn('status', ['pending', 'acknowledged'])->latest()->first();
            if ($active) return $active;

            $responsible = $conversation->channel?->handoffResponsible;
            // La inscripción a alertas por WhatsApp solo controla el canal de
            // notificación; nunca debe dejar la derivación sin dueño. Priorizamos
            // el responsable configurado, luego la asignación existente y por
            // último el propietario del canal para que siempre pueda tomarse
            // desde el CRM.
            $assignedTo = $responsible?->id
                ?? $conversation->assigned_to
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
            return $handoff;
        });
    }

    public function acknowledge(HumanHandoff $handoff, User $user): HumanHandoff
    {
        abort_unless((int) $handoff->assigned_to === (int) $user->id, 403);
        if ($handoff->status === 'pending') {
            $handoff->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);
        }
        return $handoff->fresh();
    }

    public function resolveForHumanMessage(Conversation $conversation): void
    {
        $handoff = $conversation->humanHandoffs()->whereIn('status', ['pending', 'acknowledged'])->latest()->first();
        if ($handoff) $handoff->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    public function cancelActive(Conversation $conversation): void
    {
        $conversation->humanHandoffs()
            ->whereIn('status', ['pending', 'acknowledged'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    }
}
