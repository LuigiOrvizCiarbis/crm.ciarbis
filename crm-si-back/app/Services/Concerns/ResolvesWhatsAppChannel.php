<?php

namespace App\Services\Concerns;

use App\Models\Channel;
use App\Models\WhatsAppConfig;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;
use Sentry\State\Scope;

trait ResolvesWhatsAppChannel
{
    /**
     * Resuelve el Channel a partir del `phone_number_id` de un webhook de Meta.
     * Compartido entre WhatsAppMessageService (mensajes/estados) y el servicio
     * de webhooks de grupos, que necesitan el mismo mapeo phone_number_id → canal.
     */
    private function resolveChannelFromWebhook(array $value, string $context): ?Channel
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            Log::warning("{$context}: phone_number_id ausente en metadata");
            $this->reportDroppedWebhook(
                "{$context}: phone_number_id ausente en metadata",
                ['context' => $context]
            );

            return null;
        }

        $whatsappConfig = WhatsAppConfig::with('channels')
            ->where('phone_number_id', $phoneNumberId)
            ->first();

        if (! $whatsappConfig || $whatsappConfig->channels->isEmpty()) {
            Log::warning("{$context}: canal no encontrado para phone_number_id: {$phoneNumberId}");
            // Un mensaje de cliente que no matchea ningún canal se pierde en silencio.
            // Lo reportamos como issue en Sentry para detectar config rota / tenant mal armado.
            $this->reportDroppedWebhook(
                "{$context}: canal no encontrado para phone_number_id",
                ['context' => $context, 'phone_number_id' => $phoneNumberId]
            );

            return null;
        }

        return $whatsappConfig->channels->first();
    }

    /**
     * Reporta a Sentry un webhook entrante descartado en una rama crítica
     * (sin canal resoluble → evento que se pierde). Se emite como
     * captureMessage 'warning' para que genere un issue agrupable y alertable,
     * además del Log::warning que ya queda en los logs.
     *
     * @param  array<string, mixed>  $context
     */
    private function reportDroppedWebhook(string $message, array $context): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        \Sentry\withScope(function (Scope $scope) use ($message, $context): void {
            $scope->setContext('whatsapp_webhook', $context);
            \Sentry\captureMessage($message, Severity::warning());
        });
    }
}
