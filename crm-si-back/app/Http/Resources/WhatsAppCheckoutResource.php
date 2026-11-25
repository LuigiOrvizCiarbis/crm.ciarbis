<?php

namespace App\Http\Resources;

use App\Models\WhatsAppCheckout;
use App\Services\Commerce\WooCommerceCheckoutGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resumen del pedido de WhatsApp para la tarjeta del chat.
 *
 * @mixin WhatsAppCheckout
 */
class WhatsAppCheckoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $config = $this->external_order_id ? $this->wooConfig() : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'order_number' => $this->external_order_number,
            'total' => $this->total !== null ? (float) $this->total : null,
            'currency' => $this->quote['currency'] ?? null,
            'items' => array_map(fn (array $item) => [
                'name' => $item['name'],
                'variant_label' => $item['variant_label'],
                'quantity' => $item['quantity'],
            ], $this->itemSummaries()),
            'payment_url' => $this->status === WhatsAppCheckout::AWAITING_PAYMENT ? $this->payment_url : null,
            'payment_expires_at' => $this->payment_expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'shipped_notified_at' => $this->shipped_notified_at?->toIso8601String(),
            'tracking_number' => $this->tracking_number,
            'order_admin_url' => $config ? app(WooCommerceCheckoutGateway::class)->orderAdminUrl($config, (int) $this->external_order_id) : null,
        ];
    }
}
