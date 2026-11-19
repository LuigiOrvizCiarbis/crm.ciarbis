<?php

namespace App\Console\Commands;

use App\Jobs\PlaceWhatsAppCheckoutOrderJob;
use App\Models\WhatsAppCheckout;
use App\Services\Commerce\WhatsAppCheckoutInstaller;
use App\Services\Commerce\WhatsAppCheckoutLifecycle;
use App\Services\Commerce\WhatsAppCheckoutPaymentMessage;
use App\Services\Flows\WhatsAppFlowMessageService;
use App\Services\MessagingWindowService;
use App\Services\SystemMessageSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recordatorios y vencimientos de la compra por WhatsApp: carritos sin
 * completar, pedidos sin pagar y altas cortadas a mitad de camino.
 */
class WhatsAppCheckoutsFollowUp extends Command
{
    protected $signature = 'whatsapp-checkouts:follow-up';

    protected $description = 'Recuerda y vence carritos y pedidos sin pagar de la compra por WhatsApp';

    public function handle(
        MessagingWindowService $window,
        WhatsAppCheckoutInstaller $installer,
        WhatsAppFlowMessageService $flowMessages,
        WhatsAppCheckoutPaymentMessage $paymentMessage,
        WhatsAppCheckoutLifecycle $lifecycle,
        SystemMessageSender $systemMessages,
    ): int {
        $query = fn (string $status) => WhatsAppCheckout::withoutGlobalScopes()->where('status', $status);

        // Carritos: recordatorio a la hora, descarte a las 24 h.
        $query(WhatsAppCheckout::CART)
            ->whereNull('reminded_at')
            ->where('created_at', '>', now()->subDay())
            ->where('created_at', '<=', now()->subHour())
            ->get()->each(function (WhatsAppCheckout $checkout) use ($window, $installer, $flowMessages) {
                $this->safely($checkout, function () use ($checkout, $window, $installer, $flowMessages) {
                    $conversation = $checkout->conversationUnscoped();
                    $flow = $conversation !== null && $window->isOpen($conversation) ? $installer->flowFor($conversation) : null;

                    if ($flow !== null) {
                        $message = $flowMessages->send($conversation, $flow, ['body' => '¿Querés terminar tu compra? Tu carrito sigue guardado.', 'cta' => 'Completar compra'], null);
                        $checkout->whatsapp_flow_session_id = $message->interactive['session_id'] ?? null;
                    }
                });
                $checkout->update(['reminded_at' => now()]);
            });

        $query(WhatsAppCheckout::CART)
            ->where('created_at', '<=', now()->subDay())
            ->update(['status' => WhatsAppCheckout::EXPIRED, 'closed_at' => now(), 'updated_at' => now()]);

        // Pedidos sin pagar: recordatorio 1 h antes, cierre al vencer.
        $query(WhatsAppCheckout::AWAITING_PAYMENT)
            ->whereNull('reminded_at')
            ->where('payment_expires_at', '>', now())
            ->where('payment_expires_at', '<=', now()->addHour())
            ->get()->each(function (WhatsAppCheckout $checkout) use ($window, $paymentMessage) {
                $this->safely($checkout, function () use ($checkout, $window, $paymentMessage) {
                    $conversation = $checkout->conversationUnscoped();
                    if ($conversation !== null && $window->isOpen($conversation)) {
                        $paymentMessage->send($checkout, 'whatsapp_checkout_reminder', '¿Pudiste pagar? Te dejamos el link de nuevo.');
                    }
                });
                $checkout->update(['reminded_at' => now()]);
            });

        $query(WhatsAppCheckout::AWAITING_PAYMENT)
            ->where('payment_expires_at', '<=', now())
            ->get()->each(function (WhatsAppCheckout $checkout) use ($window, $lifecycle, $systemMessages) {
                $this->safely($checkout, function () use ($checkout, $window, $lifecycle, $systemMessages) {
                    if (! $lifecycle->closeUnpaid($checkout, WhatsAppCheckout::EXPIRED)) {
                        return;
                    }

                    $conversation = $checkout->conversationUnscoped();
                    if ($conversation !== null && $window->isOpen($conversation)) {
                        $systemMessages->send($conversation, "Cancelamos el pedido #{$checkout->external_order_number} porque no se registró el pago y liberamos los productos. Si todavía lo querés, mandanos el carrito de nuevo.");
                    }
                });
            });

        // Altas cortadas: el worker murió a mitad de PlaceWhatsAppCheckoutOrderJob.
        $query(WhatsAppCheckout::PLACING)
            ->where('updated_at', '<=', now()->subMinutes(15))
            ->get()->each(fn (WhatsAppCheckout $checkout) => $this->safely(
                $checkout,
                fn () => PlaceWhatsAppCheckoutOrderJob::fail($checkout, 'No pudimos confirmar tu pedido. Probá de nuevo.'),
            ));

        return self::SUCCESS;
    }

    private function safely(WhatsAppCheckout $checkout, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('Compra por WhatsApp: falló el seguimiento', [
                'checkout_id' => $checkout->id,
                'status' => $checkout->status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
