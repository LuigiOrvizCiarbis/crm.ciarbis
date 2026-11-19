<?php

namespace App\Services\Commerce;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\WooCommerceConfig;
use App\Services\SystemMessageSender;
use Illuminate\Support\Facades\Log;

/**
 * Carrito enviado desde el catálogo de WhatsApp: lo traduce a productos
 * locales y abre la compra (formulario de envío y pago). Sin compra por
 * WhatsApp activa, el mensaje queda en el chat para que lo atienda una persona.
 */
class WhatsAppCatalogOrderService
{
    public function __construct(
        private readonly CatalogRetailerIds $retailerIds,
        private readonly WhatsAppCheckoutService $checkouts,
        private readonly SystemMessageSender $systemMessages,
    ) {}

    /** @param  array<string, mixed>  $order */
    public function handle(Message $message, Conversation $conversation, array $order): void
    {
        $config = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $conversation->tenant_id)->first();

        if ($config === null || ! $config->checkoutReady()) {
            return;
        }

        $items = [];
        $missing = 0;

        foreach ((array) ($order['product_items'] ?? []) as $item) {
            $resolved = is_array($item)
                ? $this->retailerIds->resolve($conversation->tenant_id, (string) ($item['product_retailer_id'] ?? ''), $config)
                : null;

            if ($resolved === null) {
                $missing++;

                continue;
            }

            $items[] = [
                'product_id' => $resolved['product']->id,
                'variant_id' => $resolved['variant']?->id,
                'quantity' => max(1, min(99, (int) ($item['quantity'] ?? 1))),
            ];
        }

        if ($items === []) {
            $this->notify($conversation, 'No pudimos procesar tu carrito. Un asesor te va a responder en breve.');

            return;
        }

        if ($missing > 0) {
            $this->notify($conversation, 'Algunos productos ya no están disponibles y los quitamos del pedido.');
        }

        try {
            $this->checkouts->open($conversation, $items, null);
        } catch (\Throwable $e) {
            Log::warning('Compra por WhatsApp: no se pudo abrir el checkout del carrito', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notify(Conversation $conversation, string $text): void
    {
        try {
            $this->systemMessages->send($conversation, $text);
        } catch (\Throwable $e) {
            Log::warning('Compra por WhatsApp: no se pudo avisar sobre el carrito', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
