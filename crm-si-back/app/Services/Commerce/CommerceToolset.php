<?php

namespace App\Services\Commerce;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WhatsAppCheckout;
use App\Models\WooCommerceConfig;
use App\Services\Ai\AiTool;
use App\Services\Ai\AiToolset;
use App\Services\WooCommerceCatalogWriter;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Herramientas de venta por WhatsApp: fichas del catálogo, formulario de
 * compra y reenvío del link de pago. También le cuenta al modelo el estado
 * del pedido activo de la conversación.
 */
class CommerceToolset implements AiToolset
{
    public const SHOW_PRODUCTS = 'mostrar_productos';

    public const SEND_CHECKOUT = 'enviar_formulario_compra';

    public const RESEND_PAYMENT_LINK = 'reenviar_link_pago';

    private const IN_STOCK = ['instock', 'onbackorder'];

    private const FALLBACKS = [
        self::SHOW_PRODUCTS => 'Te mandé las opciones.',
        self::SEND_CHECKOUT => 'Te mandé el formulario para completar la compra.',
        self::RESEND_PAYMENT_LINK => 'Te reenvié el link de pago.',
    ];

    /**
     * Resultado para el modelo cuando las fichas no salen. No incluye el error de
     * Meta: el modelo lo leía como falta de stock y se lo decía al cliente.
     */
    private const CARDS_UNAVAILABLE = 'No se pudieron enviar las fichas por un problema técnico de WhatsApp; no tiene que ver con el stock ni con los precios. '
        .'No se lo menciones al cliente: contale los productos, talles y precios con los datos del catálogo y, cuando elija, usá enviar_formulario_compra.';

    private ?string $lastSuccess = null;

    /**
     * @param  bool  $catalogAvailable  false si Meta no encontró los productos del catálogo hace poco: no se ofrecen fichas
     */
    public function __construct(
        private readonly Conversation $conversation,
        private readonly WooCommerceConfig $config,
        private readonly CatalogRetailerIds $retailerIds,
        private readonly WhatsAppProductMessageSender $products,
        private readonly WhatsAppCheckoutService $checkouts,
        private readonly WhatsAppCheckoutPaymentMessage $paymentMessage,
        private readonly bool $catalogAvailable = true,
    ) {}

    public function definitions(): array
    {
        return array_values(array_filter([
            $this->catalogAvailable ? new AiTool(
                self::SHOW_PRODUCTS,
                'Envía al cliente fichas de productos del catálogo de WhatsApp para que vea fotos y talles y los agregue al carrito. '
                    .'Usala cuando el cliente pregunta por productos o quiere ver opciones.',
                [
                    'type' => 'object',
                    'properties' => [
                        'product_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'minItems' => 1,
                            'maxItems' => WhatsAppProductMessageSender::MAX_PRODUCTS,
                            'description' => 'ID de los productos del catálogo.',
                        ],
                        'variant_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'ID de variantes (talles) puntuales. Si se omiten, se envían todos los talles con stock.',
                        ],
                    ],
                    'required' => ['product_ids'],
                ],
            ) : null,
            new AiTool(
                self::SEND_CHECKOUT,
                'Envía el formulario para completar la compra (envío y pago con Mercado Pago) con los productos y talles '
                    .'que el cliente ya eligió en la charla.',
                [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'product_id' => ['type' => 'integer'],
                                    'variant_id' => ['type' => 'integer', 'description' => 'Obligatorio en productos con talles.'],
                                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99],
                                ],
                                'required' => ['product_id', 'quantity'],
                            ],
                        ],
                    ],
                    'required' => ['items'],
                ],
            ),
            new AiTool(
                self::RESEND_PAYMENT_LINK,
                'Reenvía al cliente el botón de pago de Mercado Pago de su pedido pendiente. Usala si pide el link o dice que no lo encuentra.',
                ['type' => 'object', 'properties' => new \stdClass],
            ),
        ]));
    }

    public function call(string $name, array $input): string
    {
        return match ($name) {
            self::SHOW_PRODUCTS => $this->showProducts($input),
            self::SEND_CHECKOUT => $this->sendCheckout($input),
            self::RESEND_PAYMENT_LINK => $this->resendPaymentLink(),
            default => 'Herramienta desconocida.',
        };
    }

    public function promptSection(): string
    {
        $section = "## Ventas por WhatsApp\n"
            .($this->catalogAvailable
                ? 'Mostrá productos con mostrar_productos; el cliente arma el carrito en WhatsApp y lo envía. '
                : 'Las fichas del catálogo de WhatsApp no están disponibles por ahora: describí los productos, talles y precios con los datos del catálogo de este prompt. ')
            .'Si ya eligió productos y talles en la charla, usá enviar_formulario_compra. '
            .'La compra se cierra en este chat con ese formulario: aunque otras instrucciones digan que derives las compras a una persona, '
            .'no lo hagas por querer comprar; derivá sólo si el cliente lo pide o hay un problema que el formulario no resuelve. '
            .'Si quiere comprar pero no dijo el talle o la cantidad, preguntalo antes de mandar el formulario. '
            .'No pidas dirección ni datos de pago por chat. No prometas costos de envío: se calculan en el formulario. '
            .'Usá los ID del catálogo.';

        $checkout = $this->activeCheckout();
        if ($checkout === null) {
            return $section;
        }

        return $section."\n\nPedido activo del cliente: ".$this->describe($checkout)."\n"
            .'Respondé sobre el pago y el envío con estos datos; nunca confirmes un pago que no figure como pagado. '
            .'Para reenviar el link usá reenviar_link_pago.';
    }

    public function fallbackMessage(): ?string
    {
        return $this->lastSuccess !== null ? self::FALLBACKS[$this->lastSuccess] : null;
    }

    private function showProducts(array $input): string
    {
        if (! $this->catalogAvailable) {
            return self::CARDS_UNAVAILABLE;
        }

        try {
            $productIds = array_slice(array_values(array_unique(array_map('intval', (array) ($input['product_ids'] ?? [])))), 0, WhatsAppProductMessageSender::MAX_PRODUCTS);
            $variantIds = array_map('intval', (array) ($input['variant_ids'] ?? []));

            $products = Product::withoutGlobalScopes()
                ->where('tenant_id', $this->conversation->tenant_id)
                ->where('source', WooCommerceCatalogWriter::SOURCE)
                ->where('is_active', true)
                ->whereIn('id', $productIds)
                ->with(['variants' => fn ($query) => $query->withoutGlobalScopes()])
                ->get()
                ->keyBy('id');

            $ids = [];
            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                if ($product === null) {
                    continue;
                }

                if ($product->variants->isEmpty()) {
                    $ids[] = $this->retailerIds->forProduct($product, null, $this->config);

                    continue;
                }

                $chosen = $product->variants->whereIn('id', $variantIds);
                if ($chosen->isEmpty()) {
                    $chosen = $product->variants->filter(fn (ProductVariant $variant) => in_array($variant->stock_status, self::IN_STOCK, true));
                }

                foreach ($chosen as $variant) {
                    $ids[] = $this->retailerIds->forProduct($product, $variant, $this->config);
                }
            }

            $ids = array_values(array_filter($ids, 'filled'));
            if ($ids === []) {
                return 'No se pudo enviar: no encontré esos productos con stock en el catálogo.';
            }

            $this->products->sendProducts(
                $this->conversation,
                'Te comparto las opciones. Tocá un producto para ver fotos y agregarlo al carrito.',
                $ids,
                null,
            );
        } catch (CatalogUnavailableException) {
            return self::CARDS_UNAVAILABLE;
        } catch (Throwable $e) {
            $this->logFailure(self::SHOW_PRODUCTS, $e);

            return self::CARDS_UNAVAILABLE;
        }

        $this->lastSuccess = self::SHOW_PRODUCTS;

        return 'Productos enviados.';
    }

    private function sendCheckout(array $input): string
    {
        $items = array_values(array_filter(array_map(fn ($item) => is_array($item) ? [
            'product_id' => (int) ($item['product_id'] ?? 0),
            'variant_id' => isset($item['variant_id']) ? (int) $item['variant_id'] : null,
            'quantity' => (int) ($item['quantity'] ?? 1),
        ] : null, (array) ($input['items'] ?? []))));

        try {
            $this->checkouts->open($this->conversation, $items, null);
        } catch (InvalidArgumentException $e) {
            return 'No se pudo enviar: '.$e->getMessage();
        } catch (Throwable $e) {
            $this->logFailure(self::SEND_CHECKOUT, $e);

            return 'No se pudo enviar el formulario.';
        }

        $this->lastSuccess = self::SEND_CHECKOUT;

        return 'Formulario de compra enviado.';
    }

    private function resendPaymentLink(): string
    {
        $checkout = $this->latestCheckout();

        if ($checkout === null
            || $checkout->status !== WhatsAppCheckout::AWAITING_PAYMENT
            || $checkout->payment_expires_at === null
            || $checkout->payment_expires_at->isPast()) {
            return 'No hay un pedido esperando pago.';
        }

        try {
            $this->paymentMessage->send($checkout, 'whatsapp_checkout_payment_resend');
        } catch (Throwable $e) {
            $this->logFailure(self::RESEND_PAYMENT_LINK, $e);

            return 'No se pudo reenviar el link.';
        }

        $this->lastSuccess = self::RESEND_PAYMENT_LINK;

        return 'Link reenviado.';
    }

    private function latestCheckout(): ?WhatsAppCheckout
    {
        return WhatsAppCheckout::withoutGlobalScopes()
            ->where('tenant_id', $this->conversation->tenant_id)
            ->where('conversation_id', $this->conversation->id)
            ->latest('id')
            ->first();
    }

    /** Último checkout en curso, o pagado en los últimos 30 días. */
    private function activeCheckout(): ?WhatsAppCheckout
    {
        return WhatsAppCheckout::withoutGlobalScopes()
            ->where('tenant_id', $this->conversation->tenant_id)
            ->where('conversation_id', $this->conversation->id)
            ->where(fn ($query) => $query
                ->whereIn('status', [WhatsAppCheckout::CART, WhatsAppCheckout::PLACING, WhatsAppCheckout::AWAITING_PAYMENT])
                ->orWhere(fn ($paid) => $paid->where('status', WhatsAppCheckout::PAID)->where('paid_at', '>=', now()->subDays(30))))
            ->latest('id')
            ->first();
    }

    private function describe(WhatsAppCheckout $checkout): string
    {
        $tz = $checkout->timezone();
        $items = implode(', ', array_map(
            fn (array $item) => "{$item['quantity']} × {$item['name']}".($item['variant_label'] !== null ? " ({$item['variant_label']})" : ''),
            $checkout->itemSummaries(),
        ));

        return match ($checkout->status) {
            WhatsAppCheckout::CART => "armó un carrito ({$items}) y todavía no completó el formulario de compra.",
            WhatsAppCheckout::PLACING => 'confirmó el pedido y se está generando el link de pago.',
            WhatsAppCheckout::AWAITING_PAYMENT => "#{$checkout->external_order_number}, esperando pago, total "
                .($checkout->quote['currency'] ?? '').' '.WhatsAppCheckout::money((float) $checkout->total, $checkout->minorUnit())
                .', vence '.$checkout->payment_expires_at?->copy()->setTimezone($tz)->format('d/m H:i')
                .", link: {$checkout->payment_url}. Productos: {$items}.",
            default => "#{$checkout->external_order_number}, pagado el ".$checkout->paid_at?->copy()->setTimezone($tz)->format('d/m')
                .($checkout->shipped_notified_at !== null ? ', despachado, seguimiento '.($checkout->tracking_number ?? 'sin número') : '')
                .'.',
        };
    }

    private function logFailure(string $tool, Throwable $e): void
    {
        Log::warning('CommerceToolset: error ejecutando herramienta', [
            'tool' => $tool,
            'conversation_id' => $this->conversation->id,
            'error' => $e->getMessage(),
        ]);
    }
}
