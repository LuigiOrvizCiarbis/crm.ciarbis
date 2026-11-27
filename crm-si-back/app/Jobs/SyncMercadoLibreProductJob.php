<?php

namespace App\Jobs;

use App\Services\MercadoLibre\MercadoLibreCatalogSync;
use App\Services\MercadoLibre\MercadoLibreException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza un producto con Mercado Libre. Una ráfaga de cambios (p. ej. el
 * sync de WooCommerce tocando producto y variantes) queda en un solo job
 * pendiente, y dos del mismo producto nunca corren a la vez: crearían el
 * ítem dos veces.
 */
class SyncMercadoLibreProductJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public int $tenantId, public int $productId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->productId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('mercadolibre-product:'.$this->uniqueId()))->releaseAfter(15)->expireAfter(180)];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(MercadoLibreCatalogSync $sync): void
    {
        try {
            $sync->syncProduct($this->tenantId, $this->productId);
        } catch (MercadoLibreException $e) {
            // Sin autorización no hay reintento que sirva: la conexión ya
            // quedó "needs_reauth" y al reconectar se resincroniza todo.
            if ($e->reauth) {
                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Mercado Libre product sync failed', [
            'tenant_id' => $this->tenantId,
            'product_id' => $this->productId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
