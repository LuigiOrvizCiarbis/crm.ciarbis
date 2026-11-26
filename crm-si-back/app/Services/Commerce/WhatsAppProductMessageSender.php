<?php

namespace App\Services\Commerce;

use App\Exceptions\WhatsAppMessageRejectedException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Product;
use App\Models\User;
use App\Models\WooCommerceConfig;
use App\Services\MessagingWindowService;
use App\Services\WhatsAppInteractiveSender;
use App\Services\WooCommerceCatalogWriter;
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

    /** Productos a probar como miniatura del catálogo antes de darlo por no disponible. */
    private const MAX_THUMBNAIL_CANDIDATES = 5;

    public function __construct(
        private readonly WhatsAppInteractiveSender $sender,
        private readonly MessagingWindowService $window,
        private readonly WhatsAppCatalogAvailability $availability,
        private readonly CatalogRetailerIds $retailerIds,
    ) {}

    /**
     * Sin miniatura, Meta usa el primer producto del catálogo y rechaza el
     * mensaje entero si ese producto no está habilitado para WhatsApp. Se
     * indica uno que funcione y, si Meta lo rechaza, se prueba con otro.
     */
    public function sendCatalog(Conversation $conversation, string $body, ?User $sender): Message
    {
        $this->catalogId($conversation);
        $this->window->assertOpen($conversation);
        $candidates = $this->thumbnailCandidates($conversation->tenant_id);

        foreach ($candidates === [] ? [null] : $candidates as $thumbnail) {
            try {
                $message = $this->deliver($conversation, [
                    'type' => 'catalog_message',
                    'body' => ['text' => $body],
                    'action' => ['name' => 'catalog_message', ...($thumbnail !== null ? ['parameters' => ['thumbnail_product_retailer_id' => $thumbnail]] : [])],
                ], $body, ['kind' => 'catalog', 'retailer_ids' => []], $sender);
            } catch (WhatsAppMessageRejectedException $e) {
                if ($thumbnail === null || $e->metaCode !== self::META_INVALID_PARAMETER) {
                    throw $this->catalogError($e);
                }

                $this->availability->markProductMissing($conversation->tenant_id, $thumbnail);

                continue;
            }

            if ($thumbnail !== null) {
                $this->availability->rememberWorkingProduct($conversation->tenant_id, $thumbnail);
            }

            return $message;
        }

        // Ninguno de los probados está habilitado todavía (Meta habilita
        // productos de a tandas): las fichas de otros productos siguen andando.
        throw new CatalogItemsUnavailableException(
            'WhatsApp todavía no habilitó ninguno de los productos con los que se probó la portada del catálogo. '
            .'Meta revisa los productos de a tandas: probá de nuevo más tarde.'
        );
    }

    /**
     * Productos para la portada del catálogo, primero los que seguro existen en
     * WhatsApp: el último que Meta aceptó, los que llegaron en carritos de
     * clientes y los que se mandaron como fichas. Después, productos simples
     * sin "Sin stock", los más nuevos primero. Sin los que Meta ya rechazó.
     *
     * @return list<string>
     */
    private function thumbnailCandidates(int $tenantId): array
    {
        $config = WooCommerceConfig::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $local = $config === null ? [] : Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', WooCommerceCatalogWriter::SOURCE)
            ->where('is_active', true)
            ->whereDoesntHave('variants', fn ($query) => $query->withoutGlobalScopes())
            ->where(fn ($query) => $query->whereNull('custom_data->stock_status')->orWhere('custom_data->stock_status', '!=', 'Sin stock'))
            ->latest('id')
            ->limit(self::MAX_THUMBNAIL_CANDIDATES * 2)
            ->get()
            ->map(fn (Product $product) => $this->retailerIds->forProduct($product, null, $config))
            ->filter()
            ->all();

        $ids = array_values(array_unique(array_filter(
            [$this->availability->workingProduct($tenantId), ...$this->retailerIdsSeenInWhatsApp($tenantId), ...$local],
            fn ($id) => is_string($id) && $id !== '',
        )));

        return array_slice($this->availability->withoutMissingProducts($tenantId, $ids), 0, self::MAX_THUMBNAIL_CANDIDATES);
    }

    /**
     * Retailer ids que aparecieron en carritos recibidos o en fichas enviadas:
     * WhatsApp los tenía habilitados.
     *
     * @return list<string>
     */
    private function retailerIdsSeenInWhatsApp(int $tenantId): array
    {
        return Message::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('interactive->kind', ['order', 'products'])
            ->where('created_at', '>', now()->subDays(30))
            ->latest('id')
            ->limit(20)
            ->pluck('interactive')
            ->flatMap(fn ($interactive) => ($interactive['kind'] ?? null) === 'order'
                ? array_column((array) ($interactive['product_items'] ?? []), 'product_retailer_id')
                : (array) ($interactive['retailer_ids'] ?? []))
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
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
                    throw $this->catalogError($e);
                }

                $this->availability->markProductMissing($conversation->tenant_id, $missing);
                $ids = array_values(array_diff($ids, [$missing]));

                continue;
            }

            $this->availability->rememberWorkingProduct($conversation->tenant_id, $ids[0]);

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
     * Traduce el rechazo de Meta: un producto puntual no habilitado, o un 131009
     * sobre el catálogo sin producto puntual (catálogo no conectado al número).
     */
    private function catalogError(WhatsAppMessageRejectedException $e): RuntimeException
    {
        if ($this->missingRetailerId($e) !== null) {
            return new CatalogItemsUnavailableException;
        }

        if ($e->metaCode === self::META_INVALID_PARAMETER && str_contains(strtolower((string) $e->details), 'catalog')) {
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
