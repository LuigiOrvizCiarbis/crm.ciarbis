<?php

namespace App\Events;

use App\Models\WhatsAppCheckout;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Cambio de un pedido por WhatsApp que se ve en la tarjeta del chat. Muchos
 * cambios no dejan mensaje de sistema (cancelado en Woo, vencido con la
 * ventana cerrada): el front vuelve a pedir el detalle al recibirlo.
 */
class WhatsAppCheckoutUpdated implements ShouldBroadcastNow
{
    public function __construct(public WhatsAppCheckout $checkout) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversations.'.$this->checkout->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'whatsapp-checkout.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->checkout->conversation_id,
            'checkout_id' => $this->checkout->id,
            'status' => $this->checkout->status,
        ];
    }
}
