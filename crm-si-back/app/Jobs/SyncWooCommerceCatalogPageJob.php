<?php

namespace App\Jobs;

use App\Services\WooCommerceCatalogSync;
use App\Services\WooCommerceSyncException;
use App\Services\WooCommerceUrlException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza una página del catálogo de WooCommerce dentro de una corrida.
 * Los errores transitorios (red, 5xx) se reintentan; al agotar los intentos la
 * corrida queda fallida.
 */
class SyncWooCommerceCatalogPageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Por debajo del --timeout=180 de los workers y del retry_after de la cola.
    public int $timeout = 170;

    public function __construct(public int $tenantId, public string $runId, public int $page) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60];
    }

    public function handle(WooCommerceCatalogSync $sync): void
    {
        $config = $sync->activeRun($this->tenantId, $this->runId);
        if (! $config) {
            return;
        }

        try {
            $sync->syncPage($config, $this->page);
        } catch (WooCommerceUrlException $exception) {
            // Un DNS que no resolvió puede ser pasajero: se reintenta. El resto
            // (esquema, puerto, IP privada) no cambia entre reintentos.
            if ($exception->retryable) {
                throw $exception;
            }
            $sync->fail($this->tenantId, $this->runId, $exception->getMessage());
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('WooCommerce catalog sync failed', [
            'tenant_id' => $this->tenantId,
            'run_id' => $this->runId,
            'page' => $this->page,
            'exception' => $exception,
        ]);

        app(WooCommerceCatalogSync::class)->fail(
            $this->tenantId,
            $this->runId,
            match (true) {
                $exception instanceof WooCommerceSyncException, $exception instanceof WooCommerceUrlException => $exception->getMessage(),
                $exception instanceof ConnectionException => 'No se pudo conectar con la tienda. Revisá la URL.',
                default => 'No se pudo completar la sincronización con WooCommerce.',
            },
        );
    }
}
