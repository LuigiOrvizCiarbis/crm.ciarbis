<?php

namespace App\Jobs;

use App\Models\WhatsAppCheckout;
use App\Services\Commerce\CheckoutException;
use App\Services\Commerce\MercadoPagoClient;
use App\Services\Commerce\WhatsAppCheckoutInstaller;
use App\Services\Commerce\WooCommerceCheckoutGateway;
use App\Services\Flows\WhatsAppFlowMessageService;
use App\Services\MessagingWindowService;
use App\Services\SystemMessageSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Da de alta el pedido en WooCommerce (on-hold, reserva el stock) y la
 * preferencia de Mercado Pago. Sin reintentos automáticos para no duplicar
 * pedidos: un error vuelve el checkout a carrito y manda un formulario nuevo.
 */
class PlaceWhatsAppCheckoutOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $checkoutId) {}

    public function handle(WooCommerceCheckoutGateway $gateway, MercadoPagoClient $mercadoPago): void
    {
        $checkout = DB::transaction(function () {
            $checkout = WhatsAppCheckout::withoutGlobalScopes()->lockForUpdate()->find($this->checkoutId);

            if ($checkout === null || $checkout->status !== WhatsAppCheckout::PLACING) {
                return null;
            }

            $checkout->update(['payment_expires_at' => now()->addHours(2)]);

            return $checkout;
        });

        if ($checkout === null) {
            return;
        }

        try {
            $config = $checkout->wooConfig() ?? throw new CheckoutException('La tienda ya no está conectada.');
            $mpConfig = $checkout->mercadoPagoConfig();
            if (! $mpConfig?->hasCredentials()) {
                throw new CheckoutException('Mercado Pago no está conectado.');
            }

            $order = $gateway->createOrder($config, $checkout);
            $checkout->update([
                'external_order_id' => $order['id'],
                'external_order_number' => (string) $order['number'],
            ]);

            $conversation = $checkout->conversationUnscoped() ?? throw new RuntimeException('Conversación inexistente.');
            $digits = preg_replace('/\D+/', '', (string) $conversation->channel?->whatsappConfig()->withoutGlobalScopes()->value('display_phone_number'));
            $returnUrl = rtrim((string) config('app.frontend_url'), '/').'/compra-whatsapp?'.http_build_query(['pedido' => $checkout->external_order_number, 'wa' => $digits]);

            $preference = $mercadoPago->createPreference($mpConfig, $checkout, "Pedido #{$checkout->external_order_number}", $returnUrl);

            $checkout->update([
                'payment_preference_id' => $preference['id'],
                'payment_url' => $preference['init_point'],
                'status' => WhatsAppCheckout::AWAITING_PAYMENT,
            ]);
        } catch (CheckoutException $e) {
            self::fail($checkout, 'No pudimos confirmar tu pedido: '.$e->getMessage());

            return;
        } catch (Throwable $e) {
            Log::error('Compra por WhatsApp: no se pudo dar de alta el pedido', [
                'checkout_id' => $checkout->id,
                'error' => $e->getMessage(),
            ]);
            self::fail($checkout, 'No pudimos confirmar tu pedido. Probá de nuevo en unos minutos.');

            return;
        }

        SendWhatsAppCheckoutPaymentLinkJob::dispatch($checkout->id)->afterCommit();

        // El pedido ya está creado y cobrable: un error al completar el contacto no debe cancelarlo.
        try {
            $this->completeContact($checkout);
        } catch (Throwable $e) {
            Log::warning('Compra por WhatsApp: no se pudo completar el contacto', [
                'checkout_id' => $checkout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deshace el alta: cancela el pedido en Woo (devuelve el stock), vuelve el
     * checkout a carrito y, con la ventana abierta, avisa y manda un
     * formulario nuevo.
     */
    public static function fail(WhatsAppCheckout $checkout, string $message): void
    {
        if ($checkout->external_order_id !== null) {
            try {
                if ($config = $checkout->wooConfig()) {
                    app(WooCommerceCheckoutGateway::class)->cancelIfUnpaid($config, (int) $checkout->external_order_id);
                }
            } catch (Throwable $e) {
                Log::warning('Compra por WhatsApp: no se pudo cancelar el pedido fallido', [
                    'checkout_id' => $checkout->id,
                    'order_id' => $checkout->external_order_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $checkout->update([
            'status' => WhatsAppCheckout::CART,
            'external_order_id' => null,
            'external_order_number' => null,
            'payment_expires_at' => null,
        ]);

        $conversation = $checkout->conversationUnscoped();
        if ($conversation === null || ! app(MessagingWindowService::class)->isOpen($conversation)) {
            return;
        }

        try {
            app(SystemMessageSender::class)->send($conversation, $message);

            $flow = app(WhatsAppCheckoutInstaller::class)->flowFor($conversation);
            if ($flow !== null) {
                $sent = app(WhatsAppFlowMessageService::class)->send($conversation, $flow, ['body' => 'Revisá tu pedido y volvé a intentarlo.', 'cta' => 'Completar compra'], null);
                $checkout->update(['whatsapp_flow_session_id' => $sent->interactive['session_id'] ?? null]);
            }
        } catch (Throwable $e) {
            Log::warning('Compra por WhatsApp: no se pudo avisar el error del pedido', [
                'checkout_id' => $checkout->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Completa el email y el nombre del contacto si no los tenía. */
    private function completeContact(WhatsAppCheckout $checkout): void
    {
        $contact = $checkout->contact()->withoutGlobalScopes()->first();
        if ($contact === null) {
            return;
        }

        $address = $checkout->shipping_address ?? [];
        $name = trim(($address['first_name'] ?? '').' '.($address['last_name'] ?? ''));
        $updates = array_filter([
            'email' => blank($contact->email) ? $checkout->email : null,
            'name' => blank($contact->name) && $name !== '' ? $name : null,
        ]);

        if ($updates !== []) {
            $contact->update($updates);
        }
    }
}
