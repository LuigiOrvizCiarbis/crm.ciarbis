<?php

namespace App\Services\Commerce;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\WooCommerceConfig;

/**
 * Ofrece las herramientas de venta sólo en WhatsApp, con la compra por
 * WhatsApp lista y el formulario de compra instalado para el número.
 */
class CommerceToolsetFactory
{
    public function __construct(
        private readonly WhatsAppCheckoutInstaller $installer,
        private readonly CatalogRetailerIds $retailerIds,
        private readonly WhatsAppProductMessageSender $products,
        private readonly WhatsAppCheckoutService $checkouts,
        private readonly WhatsAppCheckoutPaymentMessage $paymentMessage,
    ) {}

    public function forConversation(Conversation $conversation): ?CommerceToolset
    {
        if ($conversation->channel?->type !== ChannelType::WHATSAPP) {
            return null;
        }

        $config = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $conversation->tenant_id)->first();

        if (! $config?->checkoutReady() || $this->installer->flowFor($conversation) === null) {
            return null;
        }

        return new CommerceToolset($conversation, $config, $this->retailerIds, $this->products, $this->checkouts, $this->paymentMessage);
    }
}
