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
use RuntimeException;

/**
 * Mensajes del catálogo nativo de WhatsApp: el catálogo completo o fichas de
 * productos (`product` / `product_list`) por retailer id.
 */
class WhatsAppProductMessageSender
{
    public const MAX_PRODUCTS = 30;

    /** Código de Meta para un parámetro inválido; con "catalog" en el detalle, el producto no está en el catálogo. */
    private const META_INVALID_PARAMETER = 131009;

    /** Reintentos sacando productos rechazados; Meta informa uno por respuesta. */
    private const MAX_MISSING_RETRIES = 5;

    public function __construct(
        private readonly WhatsAppInteractiveSender $sender,
        private readonly MessagingWindowService $window,
        private readonly WhatsAppCatalogAvailability $availability,
    ) {}

    public function sendCatalog(Conversation $conversation, string $body, ?User $sender): Message
    {
        $this->catalogId($conversation);
        $this->window->assertOpen($conversation);

        try {
            $message = $this->deliver($conversation, [
                'type' => 'catalog_message',
                'body' => ['text' => $body],
                'action' => ['name' => 'catalog_message'],
            ], $body, ['kind' => 'catalog', 'retailer_ids' => []], $sender);
        } catch (WhatsAppMessageRejectedException $e) {
            throw $this->catalogError($conversation, $e);
        }

        $this->availability->markAvailable($conversation->tenant_id);

        return $message;
    }

    /**
     * Manda las fichas que Meta tenga habilitadas. Si rechaza un producto
     * puntual, se recuerda, se saca de la lista y se reintenta con el resto.
     *
     * @param  list<string>  $retailerIds
     *
     * @throws CatalogItemsUnavailableException si ninguno de los productos está habilitado
     * @throws CatalogUnavailableException si Meta no encuentra el catálogo
     */
    public function sendProducts(Conversation $conversation, string $body, array $retailerIds, ?User $sender): Message
    {
        $catalogId = $this->catalogId($conversation);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', $retailerIds), 'filled'))), 0, self::MAX_PRODUCTS);

        if ($ids === []) {
            throw new InvalidArgumentException('No hay productos para enviar.');
        }

        $this->window->assertOpen($conversation);
        $ids = $this->availability->withoutMissingProducts($conversation->tenant_id, $ids);

        for ($retries = 0; ; $retries++) {
            if ($ids === []) {
                throw new CatalogItemsUnavailableException;
            }

            try {
                $message = $this->deliver($conversation, $this->productsMessage($catalogId, $body, $ids), $body, ['kind' => 'products', 'retailer_ids' => $ids], $sender);
            } catch (WhatsAppMessageRejectedException $e) {
                $missing = $this->missingRetailerId($e);
                if ($missing === null || ! in_array($missing, $ids, true) || $retries >= self::MAX_MISSING_RETRIES) {
                    throw $this->catalogError($conversation, $e);
                }

                $this->availability->markProductMissing($conversation->tenant_id, $missing);
                $ids = array_values(array_diff($ids, [$missing]));

                continue;
            }

            $this->availability->markAvailable($conversation->tenant_id);

            return $message;
        }
    }

    /** @param  list<string>  $ids */
    private function productsMessage(string $catalogId, string $body, array $ids): array
    {
        return count($ids) === 1
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
    }

    private function deliver(Conversation $conversation, array $interactive, string $body, array $stored, ?User $sender): Message
    {
        return $this->sender->deliver($conversation, ['type' => 'interactive', 'interactive' => $interactive], $body, $stored, null, $sender);
    }

    /** Retailer id que Meta no encontró ("product not found for product_retailer_id, X, in catalog_id, …"). */
    private function missingRetailerId(WhatsAppMessageRejectedException $e): ?string
    {
        return $e->metaCode === self::META_INVALID_PARAMETER
            && preg_match('/product_retailer_id,\s*([^,\s]+),\s*in catalog_id/i', (string) $e->details, $match)
            ? $match[1]
            : null;
    }

    /**
     * Traduce el rechazo de Meta: un producto puntual no habilitado no invalida
     * el catálogo; un 131009 sobre el catálogo sin producto puntual sí, y se
     * dejan de ofrecer fichas por un tiempo.
     */
    private function catalogError(Conversation $conversation, WhatsAppMessageRejectedException $e): RuntimeException
    {
        if ($this->missingRetailerId($e) !== null) {
            return new CatalogItemsUnavailableException;
        }

        if ($e->metaCode === self::META_INVALID_PARAMETER && str_contains(strtolower((string) $e->details), 'catalog')) {
            $this->availability->markUnavailable($conversation->tenant_id);

            return new CatalogUnavailableException;
        }

        return $e;
    }

    private function catalogId(Conversation $conversation): string
    {
        $catalogId = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $conversation->tenant_id)->value('meta_catalog_id');

        return filled($catalogId) ? (string) $catalogId
            : throw new InvalidArgumentException('Falta configurar el ID del catálogo de Meta.');
    }
}
