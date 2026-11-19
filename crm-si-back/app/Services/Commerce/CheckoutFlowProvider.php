<?php

namespace App\Services\Commerce;

use App\Jobs\PlaceWhatsAppCheckoutOrderJob;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WhatsAppCheckout;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WooCommerceConfig;
use App\Services\Flows\FlowProviderException;
use Illuminate\Support\Facades\DB;

/**
 * Pantallas del formulario de compra (DATOS → ENVIO → RESUMEN), armadas
 * desde la fila del checkout de la sesión.
 */
class CheckoutFlowProvider
{
    private const REQUIRED = [
        'first_name' => 'Nombre',
        'last_name' => 'Apellido',
        'phone' => 'Teléfono',
        'street' => 'Calle',
        'street_number' => 'Número',
        'city' => 'Ciudad',
        'postal_code' => 'Código postal',
    ];

    private const ADDRESS_KEYS = ['first_name', 'last_name', 'phone', 'email', 'street', 'street_number', 'apartment', 'city', 'postal_code', 'state'];

    private const GONE = 'Este formulario ya no está disponible. Pedinos uno nuevo por el chat.';

    private const CONFIRMED = 'Este pedido ya fue confirmado. Revisá el chat.';

    public function __construct(private readonly WooCommerceCheckoutGateway $gateway) {}

    public function owns(WhatsAppFlowSession $session): bool
    {
        return $session->flow?->system_key === WhatsAppFlow::SYSTEM_CHECKOUT;
    }

    public function screenData(WhatsAppFlowSession $session, string $screen): array
    {
        $checkout = $this->checkout($session);
        if ($checkout === null) {
            return ['has_error' => false];
        }

        return match ($screen) {
            'ENVIO' => $this->shippingData($checkout),
            'RESUMEN' => $this->summaryData($checkout),
            default => $this->addressData($checkout),
        };
    }

    /** @return array{screen: string, data: array}|array{complete: array} */
    public function handle(WhatsAppFlowSession $session, string $action, array $data): array
    {
        return DB::transaction(function () use ($session, $action, $data) {
            $checkout = WhatsAppCheckout::withoutGlobalScopes()
                ->where('whatsapp_flow_session_id', $session->id)
                ->lockForUpdate()
                ->first()
                ?? throw new FlowProviderException(self::GONE);

            if ($action === 'place_order' && in_array($checkout->status, [WhatsAppCheckout::PLACING, WhatsAppCheckout::AWAITING_PAYMENT, WhatsAppCheckout::PAID], true)) {
                return ['complete' => ['checkout_id' => $checkout->id]];
            }

            if ($checkout->status !== WhatsAppCheckout::CART) {
                throw new FlowProviderException(self::CONFIRMED);
            }

            return match ($action) {
                'shipping_quote' => $this->shippingQuote($checkout, $data),
                'review' => $this->review($checkout, $data),
                'place_order' => $this->placeOrder($checkout),
                default => throw new FlowProviderException('Acción no soportada.'),
            };
        });
    }

    private function shippingQuote(WhatsAppCheckout $checkout, array $data): array
    {
        $config = $this->config($checkout);
        $country = $this->gateway->defaultCountry($config);
        $states = $this->states($config, $country);
        $address = [];

        foreach ([...self::ADDRESS_KEYS, 'state_text'] as $key) {
            $address[$key] = trim((string) ($data[$key] ?? ''));
        }

        foreach (self::REQUIRED as $key => $label) {
            if ($address[$key] === '') {
                throw new FlowProviderException("Revisá: {$label}");
            }
        }

        if ($address['email'] !== '' && filter_var($address['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new FlowProviderException('Revisá: Email');
        }

        if ($states !== []) {
            $state = collect($states)->firstWhere('id', $address['state'])
                ?? throw new FlowProviderException('Revisá: Provincia');
            $address['state_name'] = (string) $state['title'];
        } else {
            $address['state'] = $address['state'] !== '' ? $address['state'] : $address['state_text'];
            $address['state_name'] = $address['state'];
        }

        $postcode = strtoupper(preg_replace('/\s+/', '', $address['postal_code']));
        if ($country === 'AR' && preg_match('/^[A-Z]?(\d{4})[A-Z]{0,3}$/', $postcode, $match)) {
            $postcode = $match[1];
        }
        $address['postal_code'] = $postcode;
        $address['country'] = $country;

        $checkout->fill([
            'shipping_address' => $address,
            'email' => $address['email'] !== '' ? $address['email'] : null,
        ]);

        $quote = $this->quote($checkout, $config, null);

        if (($quote['rates'] ?? []) === []) {
            $checkout->save();

            throw new FlowProviderException('No hay envíos disponibles para esa dirección.');
        }

        $checkout->fill([
            'quote' => $quote,
            'cart_token' => $quote['cart_token'] ?? null,
            'coupon_code' => null,
            'shipping_rate' => null,
        ])->save();

        return ['screen' => 'ENVIO', 'data' => $this->shippingData($checkout)];
    }

    private function review(WhatsAppCheckout $checkout, array $data): array
    {
        $rateId = (string) ($data['shipping_rate_id'] ?? '');
        $quote = $checkout->quote ?? throw new FlowProviderException(self::GONE);
        $coupon = strtolower(preg_replace('/\s+/', '', (string) ($data['coupon_code'] ?? '')));
        $coupon = $coupon !== '' ? $coupon : null;

        if (collect($quote['rates'] ?? [])->firstWhere('id', $rateId) === null) {
            throw new FlowProviderException('Elegí una opción de envío.');
        }

        if ($coupon !== $checkout->coupon_code) {
            $config = $this->config($checkout);

            try {
                $updated = $checkout->cart_token !== null
                    ? $this->gateway->applyCoupon($config, $checkout->cart_token, $checkout->coupon_code, $coupon)
                    : null;
            } catch (CheckoutException $e) {
                throw new FlowProviderException($e->getMessage());
            }

            if ($updated === null) {
                $updated = $this->quote($checkout, $config, $coupon);
                $checkout->cart_token = $updated['cart_token'] ?? $checkout->cart_token;
            }

            if (filled($updated['couponError'] ?? null)) {
                $checkout->save();

                throw new FlowProviderException((string) $updated['couponError']);
            }

            $quote = $updated;
        }

        // La tarifa elegida se toma de la cotización vigente (el cupón puede cambiar el envío).
        $rate = collect($quote['rates'] ?? [])->firstWhere('id', $rateId)
            ?? throw new FlowProviderException('Elegí una opción de envío.');

        $checkout->fill([
            'quote' => $quote,
            'coupon_code' => $coupon,
            'shipping_rate' => $rate,
            'total' => round((float) $quote['subtotal'] - (float) $quote['discount'] + (float) $rate['cost'], 2),
        ])->save();

        return ['screen' => 'RESUMEN', 'data' => $this->summaryData($checkout)];
    }

    /**
     * No llama a Woo ni a Mercado Pago: el alta puede acercarse a los 10 s del
     * endpoint. El link de pago llega por el chat.
     */
    private function placeOrder(WhatsAppCheckout $checkout): array
    {
        if ($checkout->shipping_rate === null || $checkout->total === null) {
            throw new FlowProviderException('Elegí una opción de envío.');
        }

        $checkout->update(['status' => WhatsAppCheckout::PLACING, 'reminded_at' => null]);

        PlaceWhatsAppCheckoutOrderJob::dispatch($checkout->id)->afterCommit();

        return ['complete' => ['checkout_id' => $checkout->id]];
    }

    private function quote(WhatsAppCheckout $checkout, WooCommerceConfig $config, ?string $coupon): array
    {
        $address = $checkout->shipping_address ?? [];

        try {
            return $this->gateway->quote($config, $this->lines($checkout), [
                'country' => $address['country'] ?? $this->gateway->defaultCountry($config),
                'state' => $address['state'] ?? '',
                'postcode' => $address['postal_code'] ?? '',
                'city' => $address['city'] ?? '',
                'address_1' => trim(($address['street'] ?? '').' '.($address['street_number'] ?? '')),
            ], $coupon);
        } catch (CheckoutException $e) {
            throw new FlowProviderException($e->getMessage());
        }
    }

    /** @return list<array{woo_id: int, quantity: int}> */
    private function lines(WhatsAppCheckout $checkout): array
    {
        $items = $checkout->items ?? [];
        $products = Product::withoutGlobalScopes()->whereIn('id', array_column($items, 'product_id'))->pluck('external_id', 'id');
        $variants = ProductVariant::withoutGlobalScopes()->whereIn('id', array_filter(array_column($items, 'variant_id')))->pluck('external_id', 'id');

        return array_map(fn (array $item) => [
            'woo_id' => (int) (($item['variant_id'] ?? null) ? $variants[$item['variant_id']] : $products[$item['product_id']]),
            'quantity' => (int) $item['quantity'],
        ], $items);
    }

    private function addressData(WhatsAppCheckout $checkout): array
    {
        $config = $this->config($checkout);
        $states = $this->states($config, $this->gateway->defaultCountry($config));
        $address = $checkout->shipping_address ?? $this->previousAddress($checkout) ?? $this->contactAddress($checkout);
        $data = ['cart_text' => $checkout->itemLines()];

        foreach (self::ADDRESS_KEYS as $key) {
            $data[$key] = (string) ($address[$key] ?? '');
        }

        return $data + [
            'states' => array_values(array_map(fn (array $s) => ['id' => (string) $s['id'], 'title' => (string) $s['title']], $states)),
            'has_states' => $states !== [],
            'has_no_states' => $states === [],
            'has_error' => false,
            'error_message' => '',
        ];
    }

    private function shippingData(WhatsAppCheckout $checkout): array
    {
        $minor = $checkout->minorUnit();

        return [
            'shipping_options' => array_map(fn (array $rate) => [
                'id' => (string) $rate['id'],
                'title' => $rate['label'].' · '.WhatsAppCheckout::money((float) $rate['cost'], $minor),
                ...(filled($rate['delivery_time'] ?? null) ? ['description' => (string) $rate['delivery_time']] : []),
            ], $checkout->quote['rates'] ?? []),
            'coupon_code' => (string) ($checkout->coupon_code ?? ''),
            'has_error' => false,
            'error_message' => '',
        ];
    }

    private function summaryData(WhatsAppCheckout $checkout): array
    {
        $minor = $checkout->minorUnit();
        $quote = $checkout->quote ?? [];
        $rate = $checkout->shipping_rate ?? [];
        $address = $checkout->shipping_address ?? [];
        $lines = [$checkout->itemLines()];

        if ($rate !== []) {
            $lines[] = "Envío: {$rate['label']} ".WhatsAppCheckout::money((float) $rate['cost'], $minor);
        }

        if ((float) ($quote['discount'] ?? 0) > 0) {
            $lines[] = 'Descuento'.($checkout->coupon_code ? " ({$checkout->coupon_code})" : '').': -'.WhatsAppCheckout::money((float) $quote['discount'], $minor);
        }

        $lines[] = 'Total: '.WhatsAppCheckout::money((float) $checkout->total, $minor);

        $where = trim(($address['street'] ?? '').' '.($address['street_number'] ?? ''))
            .(filled($address['apartment'] ?? null) ? " {$address['apartment']}" : '')
            .', '.($address['city'] ?? '')
            .(filled($address['state'] ?? null) ? ', '.($address['state_name'] ?? $address['state']) : '')
            .' ('.($address['postal_code'] ?? '').')';
        $lines[] = "Envío a: {$where}";

        return ['summary_text' => implode("\n", $lines), 'has_error' => false, 'error_message' => ''];
    }

    private function previousAddress(WhatsAppCheckout $checkout): ?array
    {
        if ($checkout->contact_id === null) {
            return null;
        }

        return WhatsAppCheckout::withoutGlobalScopes()
            ->where('contact_id', $checkout->contact_id)
            ->whereKeyNot($checkout->id)
            ->whereNotNull('shipping_address')
            ->latest('id')
            ->value('shipping_address');
    }

    private function contactAddress(WhatsAppCheckout $checkout): array
    {
        $contact = $checkout->contact()->withoutGlobalScopes()->first();
        $parts = preg_split('/\s+/', trim((string) $contact?->name), 2) ?: [];

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
            'phone' => (string) $contact?->phone,
            'email' => (string) $contact?->email,
        ];
    }

    /** Sin provincias de la tienda (o sin respuesta) el formulario pide la provincia como texto. */
    private function states(WooCommerceConfig $config, string $country): array
    {
        try {
            return $this->gateway->states($config, $country);
        } catch (CheckoutException) {
            return [];
        }
    }

    private function checkout(WhatsAppFlowSession $session): ?WhatsAppCheckout
    {
        return WhatsAppCheckout::withoutGlobalScopes()->where('whatsapp_flow_session_id', $session->id)->first();
    }

    private function config(WhatsAppCheckout $checkout): WooCommerceConfig
    {
        return $checkout->wooConfig() ?? throw new FlowProviderException(self::GONE);
    }
}
