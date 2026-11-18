<?php

namespace App\Services;

use App\Jobs\SyncWooCommerceCatalogPageJob;
use App\Models\WooCommerceConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquesta el sync del catálogo de WooCommerce en cola: una corrida por tenant,
 * un job por página de productos. Cada página persiste el progreso (que además
 * sirve de heartbeat) y encola la siguiente; la última desactiva los productos
 * que ya no están en la tienda y cierra la corrida.
 */
class WooCommerceCatalogSync
{
    // Una corrida sin progreso por más de esto se considera muerta (worker caído)
    // y se puede volver a iniciar. Cubre los reintentos con backoff de un job.
    private const STALE_AFTER_MINUTES = 20;

    private const MAX_PAGES = 1000;

    public function __construct(
        private WooCommerceService $woo,
        private WooCommerceCatalogWriter $writer,
    ) {}

    /**
     * Inicia una corrida. Devuelve false si ya hay una en curso.
     */
    public function start(WooCommerceConfig $config): bool
    {
        $runId = (string) Str::uuid();
        $now = now();

        $claimed = WooCommerceConfig::withoutGlobalScopes()
            ->whereKey($config->id)
            ->where(fn ($query) => $query
                ->whereNull('sync_status')
                ->orWhere('sync_status', '!=', WooCommerceConfig::SYNC_RUNNING)
                ->orWhere('sync_heartbeat_at', '<', $now->copy()->subMinutes(self::STALE_AFTER_MINUTES)))
            ->update([
                'sync_status' => WooCommerceConfig::SYNC_RUNNING,
                'sync_run_id' => $runId,
                'sync_started_at' => $now,
                'sync_heartbeat_at' => $now,
                'sync_finished_at' => null,
                'sync_result' => json_encode(self::emptyResult()),
                'sync_error' => null,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return false;
        }

        try {
            SyncWooCommerceCatalogPageJob::dispatch($config->tenant_id, $runId, 1);
        } catch (Throwable $exception) {
            // Sin job encolado la corrida quedaría "running" para siempre.
            $this->fail($config->tenant_id, $runId, 'No se pudo encolar la sincronización. Probá de nuevo.');

            throw $exception;
        }

        return true;
    }

    /**
     * Corrida en curso que no avanza hace más de STALE_AFTER_MINUTES (worker
     * caído o job perdido): se puede volver a iniciar.
     */
    public function isStale(WooCommerceConfig $config): bool
    {
        return $config->sync_status === WooCommerceConfig::SYNC_RUNNING
            && ($config->sync_heartbeat_at === null || $config->sync_heartbeat_at->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)));
    }

    /**
     * Config de la corrida $runId si sigue en curso; null si terminó o la
     * reemplazó otra (el job se descarta).
     */
    public function activeRun(int $tenantId, string $runId): ?WooCommerceConfig
    {
        return WooCommerceConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sync_run_id', $runId)
            ->where('sync_status', WooCommerceConfig::SYNC_RUNNING)
            ->first();
    }

    /**
     * Sincroniza una página y encola la siguiente, o cierra la corrida si era la
     * última. Las variaciones se leen antes de escribir el producto para que un
     * error de red no lo deje a medias; un reintento rehace la página entera
     * (la escritura es idempotente).
     *
     * @throws WooCommerceSyncException|WooCommerceUrlException
     */
    public function syncPage(WooCommerceConfig $config, int $page): void
    {
        $client = $this->woo->client($config);
        $batch = $this->woo->fetchProductsPage($client, $page);
        $items = array_values(array_filter($batch['items'], fn (array $item): bool => isset($item['id'])));
        $runId = $config->sync_run_id;

        $prepared = $this->writer->prepareFields($config->tenant_id, $items);
        $result = $config->sync_result ?? self::emptyResult();
        $result['skipped_fields'] = array_values(array_unique([...$result['skipped_fields'], ...$prepared['skipped']]));

        foreach ($items as $item) {
            $variations = ($item['type'] ?? null) === 'variable'
                ? $this->woo->fetchVariations($client, (int) $item['id'])
                : [];
            $product = $this->writer->upsertProduct($config->tenant_id, $item, $prepared['fields'], $runId);
            $result['variants'] += $this->writer->syncVariants($product, $variations);
            $result[$product->wasRecentlyCreated ? 'created' : 'updated']++;
            $result['total']++;
        }

        $isLastPage = $batch['items'] === []
            || ($batch['total_pages'] !== null ? $page >= $batch['total_pages'] : count($batch['items']) < WooCommerceService::PRODUCTS_PER_PAGE);

        if ($isLastPage) {
            $this->complete($config, $result);

            return;
        }

        if ($page >= self::MAX_PAGES) {
            throw new WooCommerceSyncException('La tienda supera la cantidad máxima de productos sincronizables.');
        }

        if ($this->updateRun($config, ['sync_result' => $result])) {
            SyncWooCommerceCatalogPageJob::dispatch($config->tenant_id, $config->sync_run_id, $page + 1);
        }
    }

    /**
     * Marca la corrida como fallida. Sin desactivar productos: con un sync
     * incompleto no se sabe cuáles faltan de verdad.
     */
    public function fail(int $tenantId, string $runId, string $message): void
    {
        WooCommerceConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('sync_run_id', $runId)
            ->where('sync_status', WooCommerceConfig::SYNC_RUNNING)
            ->update([
                'sync_status' => WooCommerceConfig::SYNC_FAILED,
                'sync_finished_at' => now(),
                'sync_error' => Str::limit($message, 500),
                'updated_at' => now(),
            ]);
    }

    /**
     * Cierra la corrida y desactiva lo que no vino. Con la fila bloqueada: si
     * otra corrida la reemplazó (p. ej. tras quedar trabada), esta no
     * desactiva productos que la nueva ya procesó.
     *
     * @param  array<string, mixed>  $result
     */
    private function complete(WooCommerceConfig $config, array $result): void
    {
        DB::transaction(function () use ($config, $result): void {
            $currentRun = WooCommerceConfig::withoutGlobalScopes()
                ->whereKey($config->id)
                ->where('sync_run_id', $config->sync_run_id)
                ->where('sync_status', WooCommerceConfig::SYNC_RUNNING)
                ->lockForUpdate()
                ->first(['id']);
            if (! $currentRun) {
                return;
            }

            $result['deactivated'] = $this->writer->deactivateMissing($config->tenant_id, $config->sync_run_id);
            $this->updateRun($config, [
                'sync_status' => WooCommerceConfig::SYNC_COMPLETED,
                'sync_finished_at' => now(),
                'last_synced_at' => now(),
                'sync_result' => $result,
            ]);
        });
    }

    /**
     * @return array{created: int, updated: int, deactivated: int, variants: int, total: int, skipped_fields: list<string>}
     */
    public static function emptyResult(): array
    {
        return ['created' => 0, 'updated' => 0, 'deactivated' => 0, 'variants' => 0, 'total' => 0, 'skipped_fields' => []];
    }

    /**
     * Actualiza la config solo si la corrida sigue siendo la vigente. Devuelve
     * false si otra corrida la reemplazó.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function updateRun(WooCommerceConfig $config, array $attributes): bool
    {
        if (array_key_exists('sync_result', $attributes)) {
            $attributes['sync_result'] = json_encode($attributes['sync_result']);
        }

        return WooCommerceConfig::withoutGlobalScopes()
            ->whereKey($config->id)
            ->where('sync_run_id', $config->sync_run_id)
            ->where('sync_status', WooCommerceConfig::SYNC_RUNNING)
            ->update([...$attributes, 'sync_heartbeat_at' => now(), 'updated_at' => now()]) > 0;
    }
}
