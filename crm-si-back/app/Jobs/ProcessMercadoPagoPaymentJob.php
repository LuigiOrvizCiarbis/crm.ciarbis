<?php

namespace App\Jobs;

use App\Models\MercadoPagoConfig;
use App\Models\WhatsAppCheckout;
use App\Services\Commerce\MercadoPagoClient;
use App\Services\Commerce\WhatsAppCheckoutLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Procesa una notificación de pago de Mercado Pago. No se confía en el cuerpo
 * del webhook: se vuelve a consultar el pago con la credencial del tenant.
 */
class ProcessMercadoPagoPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 60];

    public function __construct(public int $configId, public string $paymentId) {}

    public function handle(MercadoPagoClient $client, WhatsAppCheckoutLifecycle $lifecycle): void
    {
        $config = MercadoPagoConfig::withoutGlobalScopes()->find($this->configId);
        if (! $config || ! $config->hasCredentials()) {
            return;
        }

        $payment = $client->payment($config, $this->paymentId);

        // Pagos de otras ventas del mismo vendedor no son del CRM.
        if (! preg_match('/^CRM-(\d+)$/', (string) ($payment['external_reference'] ?? ''), $matches)) {
            return;
        }

        $checkout = WhatsAppCheckout::withoutGlobalScopes()
            ->where('tenant_id', $config->tenant_id)
            ->find((int) $matches[1]);

        if (! $checkout) {
            return;
        }

        $lifecycle->paymentUpdated($checkout, $payment);
    }
}
