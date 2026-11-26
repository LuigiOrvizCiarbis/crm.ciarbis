<?php

namespace App\Services\Commerce;

use App\Exceptions\WhatsAppMessageRejectedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WooCommerceConfig;
use App\Services\MessagingWindowService;
use App\Services\WhatsAppInteractiveSender;
use InvalidArgumentException;

/**
 * Mensajes del catálogo nativo de WhatsApp: el catálogo completo o fichas de
 * productos (`product` / `product_list`) por retailer id.
 */
class WhatsAppProductMessageSender
{
    public const MAX_PRODUCTS = 30;

    /** Código de Meta para un parámetro inválido; con "catalog" en el detalle, el producto no está en el catálogo. */
    private const META_INVALID_PARAMETER = 131009;

    public function __construct(
        private readonly WhatsAppInteractiveSender $sender,
        private readonly MessagingWindowService $window,
        private readonly WhatsAppCatalogAvailability $availability,
    ) {}

    public function sendCatalog(Conversation $conversation, string $body, ?User $sender): Message
    {
        $this->catalogId($conversation);
        $this->window->assertOpen($conversation);

        return $this->deliver($conversation, [
            'type' => 'catalog_message',
            'body' => ['text' => $body],
            'action' => ['name' => 'catalog_message'],
        ], $body, ['kind' => 'catalog', 'retailer_ids' => []], $sender);
    }

    /** @param  list<string>  $retailerIds */
    public function sendProducts(Conversation $conversation, string $body, array $retailerIds, ?User $sender): Message
    {
        $catalogId = $this->catalogId($conversation);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', $retailerIds), 'filled'))), 0, self::MAX_PRODUCTS);

        if ($ids === []) {
            throw new InvalidArgumentException('No hay productos para enviar.');
        }

        $this->window->assertOpen($conversation);

        $interactive = count($ids) === 1
            ? [
                'type' => 'product',
                'body' => ['text' => $body],
                'action' => ['catalog_id' => $catalogId, 'product_retailer_id' => $ids[0]],
            ]
            : [
                'type' => 'product_list',
                'header' => ['type' => 'text', 'text' => 'Productos'],
                'body' => ['text' => $body],
                'action' => ['catalog_id' => $catalogId, 'sections' => [[
                    'title' => 'Productos',
                    'product_items' => array_map(fn (string $id) => ['product_retailer_id' => $id], $ids),
                ]]],
            ];

        return $this->deliver($conversation, $interactive, $body, ['kind' => 'products', 'retailer_ids' => $ids], $sender);
    }

    /**
     * @throws CatalogUnavailableException si Meta no encuentra los productos en el catálogo conectado
     */
    private function deliver(Conversation $conversation, array $interactive, string $body, array $stored, ?User $sender): Message
    {
        try {
            $message = $this->sender->deliver($conversation, ['type' => 'interactive', 'interactive' => $interactive], $body, $stored, null, $sender);
        } catch (WhatsAppMessageRejectedException $e) {
            if ($e->metaCode === self::META_INVALID_PARAMETER && str_contains(strtolower((string) $e->details), 'catalog')) {
                $this->availability->markUnavailable($conversation->tenant_id);

                throw new CatalogUnavailableException;
            }

            throw $e;
        }

        $this->availability->markAvailable($conversation->tenant_id);

        return $message;
    }

    private function catalogId(Conversation $conversation): string
    {
        $catalogId = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $conversation->tenant_id)->value('meta_catalog_id');

        return filled($catalogId) ? (string) $catalogId
            : throw new InvalidArgumentException('Falta configurar el ID del catálogo de Meta.');
    }
}
