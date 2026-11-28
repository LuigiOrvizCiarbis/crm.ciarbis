<?php

namespace App\Services\MercadoLibre;

use App\Jobs\SyncMercadoLibreProductJob;
use App\Models\MercadoLibreConnection;
use App\Models\MercadoLibreListing;
use App\Models\MercadoLibreProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Lleva un producto del CRM a Mercado Libre: publica cada unidad vendible
 * (producto o variante) como un ítem, actualiza precio/stock/fotos/atributos
 * cuando cambian, pausa lo inactivo o sin stock y cierra lo que se borró.
 *
 * Los rechazos de ML quedan en la publicación (status error) para que el
 * usuario los corrija; los errores transitorios se propagan para que el job
 * reintente.
 */
class MercadoLibreCatalogSync
{
    private const UPDATABLE_FIELDS = ['price', 'pictures', 'attributes'];

    public function __construct(
        private readonly MercadoLibreClient $client,
        private readonly MercadoLibreCategories $categories,
        private readonly MercadoLibreProductSettings $settings,
        private readonly MercadoLibreItemBuilder $builder,
    ) {}

    /**
     * Encola el sync de todos los productos del tenant (también los inactivos:
     * así se pausan los que ya estaban publicados).
     */
    public function dispatchAll(MercadoLibreConnection $connection): void
    {
        Product::withoutGlobalScopes()
            ->where('tenant_id', $connection->tenant_id)
            ->select('id')
            ->chunkById(500, function ($products) use ($connection): void {
                foreach ($products as $product) {
                    SyncMercadoLibreProductJob::dispatch($connection->tenant_id, $product->id);
                }
            });

        $connection->forceFill(['last_full_sync_at' => now()])->save();
    }

    /**
     * @throws MercadoLibreException transitorio (reintentar) o de autorización.
     */
    public function syncProduct(int $tenantId, int $productId): void
    {
        $connection = MercadoLibreConnection::forTenant($tenantId);
        if (! $connection?->isSyncing()) {
            return;
        }

        $listings = MercadoLibreListing::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->get()
            ->keyBy(fn (MercadoLibreListing $listing) => (int) $listing->product_variant_id)
            // Colección base: en la de Eloquent except() filtra por id del modelo, no por clave.
            ->toBase();

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['variants' => fn ($query) => $query->withoutGlobalScopes()])
            ->find($productId);

        if (! $product) {
            $listings->each(fn (MercadoLibreListing $listing) => $this->close($connection, $listing));

            return;
        }

        $settings = $this->settings->ensure($connection, $product);
        $publish = $product->is_active && $settings->enabled;
        $categoryAttributes = $publish && $settings->category_id
            ? $this->categories->attributes($connection, $settings->category_id)
            : [];

        $units = $product->variants->isEmpty() ? [null] : $product->variants->all();
        $kept = [];
        foreach ($units as $variant) {
            $key = (int) $variant?->id;
            $kept[] = $key;
            $listing = $listings->get($key) ?? new MercadoLibreListing([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'status' => MercadoLibreListing::STATUS_PENDING,
            ]);

            $publish
                ? $this->publish($connection, $product, $variant, $settings, $categoryAttributes, $listing)
                : $this->pause($connection, $listing);
        }

        $listings->except($kept)->each(fn (MercadoLibreListing $listing) => $this->close($connection, $listing));
    }

    /**
     * @param  list<array<string, mixed>>  $categoryAttributes
     *
     * @throws MercadoLibreException
     */
    private function publish(
        MercadoLibreConnection $connection,
        Product $product,
        ?ProductVariant $variant,
        MercadoLibreProduct $settings,
        array $categoryAttributes,
        MercadoLibreListing $listing,
    ): void {
        $item = $this->builder->build($connection, $product, $variant, $settings, $categoryAttributes);

        if ($item['missing'] !== []) {
            $listing->fill(['status' => MercadoLibreListing::STATUS_NEEDS_ATTRIBUTES, 'missing' => $item['missing'], 'error' => null])->save();

            return;
        }

        $listing->missing = null;

        if ($listing->ml_item_id) {
            $this->update($connection, $listing, $item);

            return;
        }

        // ML no acepta publicar con stock 0: se publica cuando haya.
        if ($item['quantity'] < 1) {
            $listing->fill(['status' => MercadoLibreListing::STATUS_PAUSED, 'error' => null])->save();

            return;
        }

        $this->create($connection, $listing, $item);
    }

    /**
     * @param  array{payload: array<string, mixed>, description: ?string, quantity: int}  $item
     *
     * @throws MercadoLibreException
     */
    private function create(MercadoLibreConnection $connection, MercadoLibreListing $listing, array $item): void
    {
        $payload = $item['payload'];
        if ($item['description'] !== null) {
            $payload['description'] = ['plain_text' => $item['description']];
        }

        try {
            $created = $this->client->post($connection, '/items', $payload);
        } catch (MercadoLibreException $e) {
            $this->rejected($listing, $e);

            return;
        }

        $listing->fill([
            'ml_item_id' => (string) $created['id'],
            'ml_user_product_id' => $created['user_product_id'] ?? null,
            'ml_status' => $created['status'] ?? null,
            'permalink' => $created['permalink'] ?? null,
            'status' => MercadoLibreListing::STATUS_SYNCED,
            'error' => null,
            'payload_hash' => $this->hash($item),
            'synced_at' => now(),
        ])->save();
    }

    /**
     * Manda solo lo que puede cambiar en un ítem publicado, y solo si cambió
     * desde el último sync.
     *
     * @param  array{payload: array<string, mixed>, description: ?string, quantity: int}  $item
     *
     * @throws MercadoLibreException
     */
    private function update(MercadoLibreConnection $connection, MercadoLibreListing $listing, array $item): void
    {
        $hash = $this->hash($item);
        if ($listing->payload_hash === $hash && in_array($listing->status, [MercadoLibreListing::STATUS_SYNCED, MercadoLibreListing::STATUS_PAUSED], true)) {
            return;
        }

        $inStock = $item['quantity'] > 0;
        $body = Arr::only($item['payload'], self::UPDATABLE_FIELDS);
        if ($inStock) {
            $body['available_quantity'] = $item['quantity'];
        }
        if ($inStock && $listing->ml_status === 'paused') {
            $body['status'] = 'active';
        } elseif (! $inStock && $listing->ml_status !== 'paused') {
            $body['status'] = 'paused';
        }

        try {
            $updated = $this->client->put($connection, '/items/'.$listing->ml_item_id, $body);
            if ($item['description'] !== null) {
                $this->client->put($connection, '/items/'.$listing->ml_item_id.'/description?api_version=2', ['plain_text' => $item['description']]);
            }
        } catch (MercadoLibreException $e) {
            if ($this->itemGone($connection, $listing, $e)) {
                // Cerrado o borrado desde Mercado Libre: se vuelve a publicar.
                $listing->fill(['ml_item_id' => null, 'ml_user_product_id' => null, 'ml_status' => null, 'permalink' => null, 'payload_hash' => null]);
                $item['quantity'] > 0
                    ? $this->create($connection, $listing, $item)
                    : $listing->fill(['status' => MercadoLibreListing::STATUS_PAUSED, 'error' => null])->save();

                return;
            }

            $this->rejected($listing, $e);

            return;
        }

        $listing->fill([
            'ml_status' => $updated['status'] ?? $listing->ml_status,
            'permalink' => $updated['permalink'] ?? $listing->permalink,
            'status' => $inStock ? MercadoLibreListing::STATUS_SYNCED : MercadoLibreListing::STATUS_PAUSED,
            'error' => null,
            'payload_hash' => $hash,
            'synced_at' => now(),
        ])->save();
    }

    /**
     * Producto inactivo o excluido de ML: se pausa lo publicado. Lo que nunca
     * llegó a publicarse no deja rastro.
     *
     * @throws MercadoLibreException
     */
    private function pause(MercadoLibreConnection $connection, MercadoLibreListing $listing): void
    {
        if (! $listing->ml_item_id) {
            if ($listing->exists) {
                $listing->delete();
            }

            return;
        }

        if ($listing->ml_status !== 'paused' && $listing->ml_status !== 'closed') {
            try {
                $updated = $this->client->put($connection, '/items/'.$listing->ml_item_id, ['status' => 'paused']);
                $listing->ml_status = $updated['status'] ?? 'paused';
            } catch (MercadoLibreException $e) {
                if (! $this->itemGone($connection, $listing, $e)) {
                    $this->rejected($listing, $e);

                    return;
                }
                $listing->ml_status = 'closed';
            }
        }

        // Sin hash: al reactivarse se manda todo de nuevo.
        $listing->fill(['status' => MercadoLibreListing::STATUS_PAUSED, 'error' => null, 'missing' => null, 'payload_hash' => null])->save();
    }

    /**
     * Producto o variante borrados: se cierra el ítem y se olvida la fila.
     *
     * @throws MercadoLibreException
     */
    private function close(MercadoLibreConnection $connection, MercadoLibreListing $listing): void
    {
        if ($listing->ml_item_id && $listing->ml_status !== 'closed') {
            try {
                $this->client->put($connection, '/items/'.$listing->ml_item_id, ['status' => 'closed']);
            } catch (MercadoLibreException $e) {
                if ($e->isTransient() || $e->reauth) {
                    throw $e;
                }

                Log::warning('No se pudo cerrar el ítem de Mercado Libre', ['item_id' => $listing->ml_item_id, 'error' => $e->getMessage()]);
            }
        }

        $listing->delete();
    }

    /**
     * ¿El ítem ya no existe o quedó cerrado en ML? Solo se consulta ante un
     * rechazo, que es cuando puede haber pasado.
     *
     * @throws MercadoLibreException
     */
    private function itemGone(MercadoLibreConnection $connection, MercadoLibreListing $listing, MercadoLibreException $error): bool
    {
        if ($error->isTransient() || $error->reauth) {
            throw $error;
        }

        if ($error->status === 404) {
            return true;
        }

        try {
            return ($this->client->get($connection, '/items/'.$listing->ml_item_id)['status'] ?? null) === 'closed';
        } catch (MercadoLibreException $e) {
            if ($e->isTransient() || $e->reauth) {
                throw $e;
            }

            return $e->status === 404;
        }
    }

    /**
     * Rechazo de ML: queda en la publicación. Los errores transitorios y de
     * autorización se propagan.
     *
     * @throws MercadoLibreException
     */
    private function rejected(MercadoLibreListing $listing, MercadoLibreException $error): void
    {
        if ($error->isTransient() || $error->reauth) {
            throw $error;
        }

        $listing->fill(['status' => MercadoLibreListing::STATUS_ERROR, 'error' => $error->getMessage(), 'payload_hash' => null])->save();
    }

    /**
     * @param  array{payload: array<string, mixed>, description: ?string, quantity: int}  $item
     */
    private function hash(array $item): string
    {
        return hash('sha256', json_encode([
            Arr::only($item['payload'], self::UPDATABLE_FIELDS),
            $item['quantity'],
            $item['description'],
        ]));
    }
}
