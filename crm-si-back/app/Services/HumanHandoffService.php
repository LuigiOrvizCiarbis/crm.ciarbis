<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\HumanHandoff;
use App\Models\Message;
use App\Models\User;
use App\Jobs\SendHumanHandoffNotificationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;

class HumanHandoffService
{
    public function requestPhoneVerification(User $user, string $phone): void
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '549')) $digits = '54'.substr($digits, 3);
        if ($digits === '') throw new \InvalidArgumentException('El número de WhatsApp no es válido.');
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        cache()->put('handoff_phone_verification:'.$user->id, ['phone' => $digits, 'hash' => Hash::make($code)], now()->addMinutes(10));
        $cfg = config('services.si_crm_alerts');
        if (! $cfg['enabled'] || ! $cfg['phone_number_id'] || ! $cfg['access_token']) throw new \RuntimeException('SI CRM Alertas no está configurado.');
        $locale = $user->preferencesWithDefaults()['locale'] ?? 'es';
        $locale = in_array($locale, ['es', 'en'], true) ? $locale : 'es';
        $response = Http::withToken($cfg['access_token'])->timeout(10)->post('https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0').'/'.$cfg['phone_number_id'].'/messages', [
            'messaging_product' => 'whatsapp', 'to' => $digits, 'type' => 'template',
            'template' => ['name' => $cfg['verification_templates'][$locale], 'language' => ['code' => $locale === 'en' ? 'en_US' : 'es_AR'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]]]],
        ]);
        if (! $response->successful()) throw new \RuntimeException('No se pudo enviar el código de verificación.');
    }

    public function confirmPhoneVerification(User $user, string $code): void
    {
        $value = cache()->get('handoff_phone_verification:'.$user->id);
        if (! is_array($value) || ! Hash::check($code, $value['hash'] ?? '')) throw new \InvalidArgumentException('El código es inválido o expiró.');
        $user->forceFill([
            'whatsapp_notification_phone' => $value['phone'],
            'whatsapp_notification_phone_normalized' => $value['phone'],
            'whatsapp_notification_verified_at' => now(),
            'whatsapp_notification_opted_in_at' => now(),
        ])->save();
        cache()->forget('handoff_phone_verification:'.$user->id);
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
