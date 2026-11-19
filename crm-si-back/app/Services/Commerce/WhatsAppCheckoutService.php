<?php

namespace App\Services\Commerce;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WhatsAppCheckout;
use App\Models\WooCommerceConfig;
use App\Services\Flows\WhatsAppFlowMessageService;
use App\Services\MessagingWindowService;
use App\Services\SystemMessageSender;
use App\Services\WooCommerceCatalogWriter;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Abre una compra por WhatsApp: guarda el carrito y manda el formulario de
 * compra. Un carrito nuevo reemplaza al pedido sin pagar anterior.
 */
class WhatsAppCheckoutService
{
    public const FLOW_OPTIONS = ['body' => 'Completá el envío y pagá tu pedido con Mercado Pago.', 'cta' => 'Completar compra'];

    public function __construct(
        private readonly WhatsAppCheckoutInstaller $installer,
        private readonly WhatsAppFlowMessageService $flowMessages,
        private readonly WhatsAppCheckoutLifecycle $lifecycle,
        private readonly MessagingWindowService $window,
        private readonly SystemMessageSender $systemMessages,
    ) {}

    /**
     * @param  list<array{product_id: int, variant_id?: ?int, quantity: int}>  $items
     *
     * @throws InvalidArgumentException con un mensaje apto para el agente
     */
    public function open(Conversation $conversation, array $items, ?User $sender): Message
    {
        if ($conversation->channel?->type !== ChannelType::WHATSAPP) {
            throw new InvalidArgumentException('La compra por WhatsApp sólo está disponible en canales de WhatsApp.');
        }

        $config = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $conversation->tenant_id)->first();
        if (! $config?->checkoutReady()) {
            throw new InvalidArgumentException('La compra por WhatsApp no está activada.');
        }

        $flow = $this->installer->flowFor($conversation)
            ?? throw new InvalidArgumentException('Falta instalar el formulario de compra.');

        $lines = $this->normalize($conversation->tenant_id, $items);

        $checkout = WhatsAppCheckout::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'status' => WhatsAppCheckout::CART,
            'items' => $lines,
        ]);

        try {
            $message = $this->flowMessages->send($conversation, $flow, self::FLOW_OPTIONS, $sender);
        } catch (Throwable $e) {
            $checkout->delete();

            throw $e;
        }

        $checkout->update(['whatsapp_flow_session_id' => $message->interactive['session_id'] ?? null]);

        $this->closePrevious($conversation, $checkout);

        return $message;
    }

    /**
     * Valida los productos del tenant y une las líneas repetidas.
     *
     * @return list<array{product_id: int, variant_id: ?int, quantity: int}>
     */
    private function normalize(int $tenantId, array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('El carrito está vacío.');
        }

        $merged = [];
        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $variantId = isset($item['variant_id']) && $item['variant_id'] !== null ? (int) $item['variant_id'] : null;
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity < 1 || $quantity > 99) {
                throw new InvalidArgumentException('La cantidad de cada producto tiene que ser de 1 a 99.');
            }

            $product = Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('source', WooCommerceCatalogWriter::SOURCE)
                ->find($productId)
                ?? throw new InvalidArgumentException("El producto {$productId} no es de la tienda.");

            $hasVariants = ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->exists();
            if ($variantId !== null) {
                ProductVariant::withoutGlobalScopes()->where('product_id', $product->id)->whereKey($variantId)->exists()
                    ?: throw new InvalidArgumentException("El talle {$variantId} no es de «{$product->name}».");
            } elseif ($hasVariants || $product->external_type === 'variable') {
                throw new InvalidArgumentException("Elegí un talle de «{$product->name}».");
            }

            $key = $productId.':'.($variantId ?? '');
            $merged[$key] = [
                'product_id' => $productId,
                'variant_id' => $variantId,
                'quantity' => min(99, ($merged[$key]['quantity'] ?? 0) + $quantity),
            ];
        }

        return array_values($merged);
    }

    /**
     * Va después del envío: un error al mandar el formulario no cancela el
     * pedido anterior. Liberar ese stock antes de cotizar permite cambiar de
     * talle la última unidad.
     */
    private function closePrevious(Conversation $conversation, WhatsAppCheckout $current): void
    {
        $previous = WhatsAppCheckout::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->whereKeyNot($current->id)
            ->whereIn('status', [WhatsAppCheckout::CART, WhatsAppCheckout::AWAITING_PAYMENT])
            ->get();

        foreach ($previous as $old) {
            if ($old->status === WhatsAppCheckout::CART) {
                $old->update(['status' => WhatsAppCheckout::CANCELLED, 'closed_at' => now()]);

                continue;
            }

            try {
                if ($this->lifecycle->closeUnpaid($old, WhatsAppCheckout::CANCELLED) && $this->window->isOpen($conversation)) {
                    $this->systemMessages->send($conversation, "Reemplazamos tu pedido #{$old->external_order_number} por este nuevo. El link de pago anterior ya no funciona.");
                }
            } catch (Throwable $e) {
                Log::warning('Compra por WhatsApp: no se pudo cerrar el pedido anterior', [
                    'checkout_id' => $old->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
