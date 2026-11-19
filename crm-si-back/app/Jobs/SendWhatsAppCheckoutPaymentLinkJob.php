<?php

namespace App\Jobs;

use App\Models\WhatsAppCheckout;
use App\Services\Commerce\WhatsAppCheckoutPaymentMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Manda por el chat el botón de pago de un pedido recién confirmado. */
class SendWhatsAppCheckoutPaymentLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $checkoutId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(WhatsAppCheckoutPaymentMessage $payment): void
    {
        $checkout = WhatsAppCheckout::withoutGlobalScopes()->find($this->checkoutId);

        if ($checkout === null || $checkout->status !== WhatsAppCheckout::AWAITING_PAYMENT || $checkout->payment_link_message_id !== null) {
            return;
        }

        $message = $payment->send($checkout, 'whatsapp_checkout_payment');
        $checkout->update(['payment_link_message_id' => $message->id]);
    }
}
