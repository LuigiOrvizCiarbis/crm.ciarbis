<?php

namespace App\Services\Commerce;

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

    public function __construct(
        private readonly WhatsAppInteractiveSender $sender,
        private readonly MessagingWindowService $window,
    ) {}

    public function sendCatalog(Conversation $conversation, string $body, ?User $sender): Message
    {
        $this->catalogId($conversation);
        $this->window->assertOpen($conversation);

        return $this->sender->deliver($conversation, ['type' => 'interactive', 'interactive' => [
            'type' => 'catalog_message',
            'body' => ['text' => $body],
            'action' => ['name' => 'catalog_message'],
        ]], $body, ['kind' => 'catalog', 'retailer_ids' => []], null, $sender);
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

        return $this->sender->deliver($conversation, ['type' => 'interactive', 'interactive' => $interactive], $body,
            ['kind' => 'products', 'retailer_ids' => $ids], null, $sender);
    }

    private function catalogId(Conversation $conversation): string
    {
        $catalogId = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $conversation->tenant_id)->value('meta_catalog_id');

        return filled($catalogId) ? (string) $catalogId
            : throw new InvalidArgumentException('Falta configurar el ID del catálogo de Meta.');
    }
}
