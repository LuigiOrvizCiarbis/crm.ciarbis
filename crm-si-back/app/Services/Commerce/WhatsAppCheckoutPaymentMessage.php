<?php

namespace App\Services\Commerce;

use App\Models\Message;
use App\Models\WhatsAppCheckout;
use App\Services\WhatsAppInteractiveSender;
use RuntimeException;

/**
 * Único armador del botón de pago con Mercado Pago: lo usan el envío del
 * link, el recordatorio y el reenvío.
 */
class WhatsAppCheckoutPaymentMessage
{
    /** WhatsApp rechaza el mensaje entero si el texto del botón pasa de 20 caracteres. */
    public const BUTTON_TEXT = 'Pagar ahora';

    public function __construct(private readonly WhatsAppInteractiveSender $sender) {}

    public function send(WhatsAppCheckout $checkout, string $kind, ?string $lead = null): Message
    {
        $conversation = $checkout->conversationUnscoped()
            ?? throw new RuntimeException('La conversación del pedido ya no existe.');

        return $this->sender->send($conversation, $this->body($checkout, $lead), [
            'header' => null,
            'footer' => null,
            'button_text' => self::BUTTON_TEXT,
            'url' => $checkout->payment_url,
        ], null, ['kind' => $kind, 'checkout_id' => $checkout->id]);
    }

    private function body(WhatsAppCheckout $checkout, ?string $lead): string
    {
        $minor = $checkout->minorUnit();
        $rate = $checkout->shipping_rate ?? [];
        $discount = (float) ($checkout->quote['discount'] ?? 0);
        $lines = array_filter([$lead, "Pedido #{$checkout->external_order_number}", $checkout->itemLines()], fn ($line) => filled($line));

        if ($rate !== []) {
            $lines[] = "Envío: {$rate['label']} ".WhatsAppCheckout::money((float) $rate['cost'], $minor);
        }

        if ($discount > 0) {
            $lines[] = 'Descuento: -'.WhatsAppCheckout::money($discount, $minor);
        }

        $lines[] = 'Total: '.WhatsAppCheckout::money((float) $checkout->total, $minor);
        $lines[] = 'Pagás con Mercado Pago desde el botón de abajo.';

        if ($checkout->payment_expires_at !== null) {
            $lines[] = 'El link vence a las '.$checkout->payment_expires_at->copy()->setTimezone($checkout->timezone())->format('H:i').'.';
        }

        return implode("\n", $lines);
    }
}
