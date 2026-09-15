<?php

namespace App\Jobs;

use App\Models\HandoffNotificationAttempt;
use App\Models\HumanHandoff;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendHumanHandoffNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public function __construct(public int $handoffId, public string $forcedDestination = 'user') {}

    public function handle(): void
    {
        $handoff = HumanHandoff::with(['assignee', 'channel.whatsappConfig', 'conversation.contact'])->find($this->handoffId);
        if (! $handoff || ! $handoff->isActive()) return;

        $cfg = config('services.si_crm_alerts');
        if (! $cfg['enabled'] || ! $cfg['phone_number_id'] || ! $cfg['access_token']) {
            $this->recordFailure($handoff, 'Servicio SI CRM Alertas no configurado.', $this->forcedDestination);
            return;
        }

        $destination = $this->forcedDestination === 'user' && $handoff->assignee?->whatsapp_notification_verified_at
            ? $handoff->assignee->whatsapp_notification_phone_normalized
            : null;
        $type = $this->forcedDestination;
        if (! $destination && $cfg['channel_fallback_enabled'] && $handoff->channel?->whatsappConfig?->is_on_biz_app) {
            $destination = preg_replace('/\D+/', '', (string) $handoff->channel->whatsappConfig->display_phone_number);
            if (str_starts_with($destination, '549')) $destination = '54'.substr($destination, 3);
            $type = 'channel';
        }
        if (! $destination) {
            $this->recordFailure($handoff, 'No hay un WhatsApp verificado ni fallback de canal disponible.', $type);
            return;
        }

        $attempt = HandoffNotificationAttempt::firstOrCreate([
            'human_handoff_id' => $handoff->id,
            'destination_type' => $type,
        ], ['phone' => $destination]);
        if ($attempt->external_id || in_array($attempt->status, ['sent', 'delivered', 'read'], true)) return;

        $preferences = $handoff->assignee?->preferencesWithDefaults() ?? [];
        $locale = $preferences['locale'] ?? 'es';
        $locale = in_array($locale, ['es', 'en'], true) ? $locale : 'es';
        $template = $cfg['handoff_templates'][$locale] ?? $cfg['handoff_templates']['es'];
        $summary = $handoff->summary ?: 'El cliente pidió hablar con una persona.';
        $url = rtrim(config('app.frontend_url', env('FRONTEND_URL', config('app.url'))), '/').'/chats?chat='.$handoff->conversation_id;

        try {
            $response = Http::withToken($cfg['access_token'])->timeout(10)->post('https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0').'/'.$cfg['phone_number_id'].'/messages', [
                'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $destination,
                'type' => 'template', 'template' => [
                    'name' => $template, 'language' => ['code' => $locale === 'en' ? 'en_US' : 'es_AR'],
                    'components' => [['type' => 'body', 'parameters' => [
                        ['type' => 'text', 'text' => $handoff->assignee?->name ?? 'equipo'],
                        ['type' => 'text', 'text' => $handoff->channel?->name ?? 'WhatsApp'],
                        ['type' => 'text', 'text' => $summary],
                    ]], ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => (string) $handoff->conversation_id]]]],
                ],
            ]);
            if (! $response->successful()) throw new \RuntimeException($response->body());
            $attempt->update(['status' => 'sent', 'external_id' => $response->json('messages.0.id'), 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $this->recordFailure($handoff, $e->getMessage(), $type, $destination);
            if ($type === 'user' && $cfg['channel_fallback_enabled'] && $handoff->channel?->whatsappConfig?->is_on_biz_app) {
                self::dispatch($handoff->id, 'channel');
            }
        }
    }

    private function recordFailure(HumanHandoff $handoff, string $error, string $type = 'user', ?string $phone = null): void
    {
        HandoffNotificationAttempt::updateOrCreate([
            'human_handoff_id' => $handoff->id, 'destination_type' => $type,
        ], ['phone' => $phone ?: ($handoff->assignee?->whatsapp_notification_phone_normalized ?: 'unknown'), 'status' => 'failed', 'error' => $error]);
        Log::warning('Human handoff notification failed', ['handoff_id' => $handoff->id, 'error' => $error]);
    }
}
