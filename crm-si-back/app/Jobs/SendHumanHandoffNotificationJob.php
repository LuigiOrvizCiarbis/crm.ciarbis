<?php

namespace App\Jobs;

use App\Enums\ChannelType;
use App\Models\HandoffNotificationAttempt;
use App\Models\HumanHandoff;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Avisa por WhatsApp, desde el número central de SI CRM, al número del canal
 * donde el cliente pidió hablar con una persona. Sólo tiene sentido si ese
 * número está en la app WhatsApp Business (coexistence): un número sólo Cloud
 * API no tiene un teléfono donde leer la alerta. El aviso in-app sale aparte
 * (DomainNotifier), así que acá no llegar a mandar no deja la derivación muda.
 */
class SendHumanHandoffNotificationJob implements ShouldQueue
{
    use Queueable;

    public const DESTINATION_CHANNEL = 'channel';

    public int $tries = 1;

    public function __construct(public int $handoffId) {}

    public function handle(): void
    {
        $handoff = HumanHandoff::with(['assignee', 'channel.whatsappConfig'])->find($this->handoffId);
        if (! $handoff || ! $handoff->isActive()) {
            return;
        }

        $channel = $handoff->channel;
        $config = $channel?->whatsappConfig;
        if ($channel?->type !== ChannelType::WHATSAPP || ! $channel->handoff_whatsapp_alert_enabled || ! $config?->is_on_biz_app) {
            return;
        }

        $cfg = config('services.si_crm_alerts');
        if (! $cfg['enabled'] || ! $cfg['phone_number_id'] || ! $cfg['access_token']) {
            Log::warning('Human handoff: SI CRM Alertas no configurado', ['handoff_id' => $handoff->id]);

            return;
        }

        $destination = preg_replace('/\D+/', '', (string) $config->display_phone_number);
        if (str_starts_with($destination, '549')) {
            $destination = '54'.substr($destination, 3);
        }
        if ($destination === '') {
            $this->recordFailure($handoff, 'unknown', 'El canal no tiene número visible.');

            return;
        }

        $attempt = HandoffNotificationAttempt::firstOrCreate([
            'human_handoff_id' => $handoff->id,
            'destination_type' => self::DESTINATION_CHANNEL,
        ], ['phone' => $destination]);
        if ($attempt->external_id || in_array($attempt->status, ['sent', 'delivered', 'read'], true)) {
            return;
        }

        $locale = $handoff->assignee?->preferencesWithDefaults()['locale'] ?? 'es';
        $locale = in_array($locale, ['es', 'en'], true) ? $locale : 'es';

        try {
            $response = Http::withToken($cfg['access_token'])
                ->timeout(10)
                ->post('https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0').'/'.$cfg['phone_number_id'].'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $destination,
                    'type' => 'template',
                    'template' => [
                        'name' => $cfg['handoff_templates'][$locale] ?? $cfg['handoff_templates']['es'],
                        'language' => ['code' => $locale === 'en' ? 'en_US' : 'es_AR'],
                        'components' => [
                            ['type' => 'body', 'parameters' => [
                                ['type' => 'text', 'text' => $handoff->assignee?->name ?? 'equipo'],
                                ['type' => 'text', 'text' => $channel->name ?: 'WhatsApp'],
                                ['type' => 'text', 'text' => $handoff->summary ?: 'El cliente pidió hablar con una persona.'],
                            ]],
                            // La plantilla tiene un botón URL dinámico cuya base
                            // apunta a /chats?chat= del front.
                            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [
                                ['type' => 'text', 'text' => (string) $handoff->conversation_id],
                            ]],
                        ],
                    ],
                ]);
            if (! $response->successful()) {
                throw new \RuntimeException($response->body());
            }
            $attempt->update(['status' => 'sent', 'external_id' => $response->json('messages.0.id'), 'sent_at' => now()]);
        } catch (\Throwable $e) {
            $this->recordFailure($handoff, $destination, $e->getMessage());
        }
    }

    private function recordFailure(HumanHandoff $handoff, string $phone, string $error): void
    {
        HandoffNotificationAttempt::updateOrCreate(
            ['human_handoff_id' => $handoff->id, 'destination_type' => self::DESTINATION_CHANNEL],
            ['phone' => $phone, 'status' => 'failed', 'error' => $error],
        );
        Log::warning('Human handoff notification failed', ['handoff_id' => $handoff->id, 'error' => $error]);
    }
}
