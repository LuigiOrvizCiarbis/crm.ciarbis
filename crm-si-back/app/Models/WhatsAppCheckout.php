<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compra por WhatsApp: del carrito recibido al pedido pagado en WooCommerce.
 */
class WhatsAppCheckout extends Model
{
    use BelongsToTenant;

    public const CART = 'cart';

    public const PLACING = 'placing';

    public const AWAITING_PAYMENT = 'awaiting_payment';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $table = 'whatsapp_checkouts';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'contact_id',
        'whatsapp_flow_session_id',
        'status',
        'items',
        'quote',
        'cart_token',
        'shipping_address',
        'shipping_rate',
        'coupon_code',
        'email',
        'total',
        'external_order_id',
        'external_order_number',
        'payment_preference_id',
        'payment_url',
        'payment_expires_at',
        'payment_id',
        'payment_link_message_id',
        'opportunity_id',
        'reminded_at',
        'paid_at',
        'shipped_notified_at',
        'payment_failed_notified_at',
        'closed_at',
        'tracking_number',
    ];

    protected $hidden = ['cart_token'];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'quote' => 'array',
            'shipping_address' => 'array',
            'shipping_rate' => 'array',
            'total' => 'decimal:2',
            'external_order_id' => 'integer',
            'payment_expires_at' => 'datetime',
            'reminded_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_notified_at' => 'datetime',
            'payment_failed_notified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function flowSession(): BelongsTo
    {
        return $this->belongsTo(WhatsAppFlowSession::class, 'whatsapp_flow_session_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function wooConfig(): ?WooCommerceConfig
    {
        return WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $this->tenant_id)->first();
    }

    public function mercadoPagoConfig(): ?MercadoPagoConfig
    {
        return MercadoPagoConfig::withoutGlobalScopes()->where('tenant_id', $this->tenant_id)->first();
    }

    /** Conversación sin el scope de tenant (jobs y comandos no tienen usuario). */
    public function conversationUnscoped(): ?Conversation
    {
        return Conversation::withoutGlobalScopes()->with([
            'channel' => fn ($q) => $q->withoutGlobalScopes(),
            'contact' => fn ($q) => $q->withoutGlobalScopes(),
        ])->find($this->conversation_id);
    }

    /** Decimales de la moneda de la cotización (2 hasta cotizar). */
    public function minorUnit(): int
    {
        return (int) ($this->quote['minor'] ?? 2);
    }

    public static function money(float $amount, int $minor = 2): string
    {
        return '$'.number_format($amount, $minor > 0 && fmod($amount, 1) != 0 ? 2 : 0, ',', '.');
    }

    /**
     * Productos del carrito con sus datos locales.
     *
     * @return list<array{name: string, variant_label: ?string, quantity: int, unit_price: float}>
     */
    public function itemSummaries(): array
    {
        $items = $this->items ?? [];
        $products = Product::withoutGlobalScopes()->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');
        $variants = ProductVariant::withoutGlobalScopes()->whereIn('id', array_filter(array_column($items, 'variant_id')))->get()->keyBy('id');

        return array_map(function (array $item) use ($products, $variants) {
            $product = $products->get($item['product_id']);
            $variant = ($item['variant_id'] ?? null) ? $variants->get($item['variant_id']) : null;

            return [
                'name' => (string) ($product?->name ?? 'Producto'),
                'variant_label' => $variant ? self::variantLabel($variant) : null,
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) ($variant?->price ?? $product?->price ?? 0),
            ];
        }, $items);
    }

    public static function variantLabel(ProductVariant $variant): string
    {
        $label = implode(' / ', array_filter(array_map('strval', (array) $variant->attribute_values)));

        return $label !== '' ? $label : 'Único';
    }

    /** Una línea por producto: "1 × Nombre (Talle) $precio", con el precio cotizado si lo hay. */
    public function itemLines(): string
    {
        $quoted = $this->quote['items'] ?? [];
        $lines = [];

        foreach ($this->itemSummaries() as $i => $item) {
            $line = "{$item['quantity']} × {$item['name']}".($item['variant_label'] !== null ? " ({$item['variant_label']})" : '');
            $total = (float) ($quoted[$i]['line_total'] ?? $item['unit_price'] * $item['quantity']);
            $lines[] = $total > 0 ? $line.' '.self::money($total, $this->minorUnit()) : $line;
        }

        return implode("\n", $lines);
    }

    public function timezone(): string
    {
        return Tenant::find($this->tenant_id)?->timezone ?: config('app.timezone');
    }
}
