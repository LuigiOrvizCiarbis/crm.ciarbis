<?php

namespace App\Console\Commands;

use App\Models\MercadoLibreConnection;
use App\Services\MercadoLibre\MercadoLibreCatalogSync;
use App\Services\MercadoLibre\MercadoLibreClient;
use App\Services\MercadoLibre\MercadoLibreException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Renueva la autorización de cada cuenta de Mercado Libre conectada (con el
 * sync encendido o no) y resincroniza el catálogo de las que sincronizan. El
 * resync cubre los cambios que no disparan eventos (updates masivos).
 */
class SyncMercadoLibreCatalogs extends Command
{
    protected $signature = 'mercadolibre:sync';

    protected $description = 'Renueva los tokens de Mercado Libre y encola el sync del catálogo de las cuentas que sincronizan';

    public function handle(MercadoLibreClient $client, MercadoLibreCatalogSync $sync): int
    {
        MercadoLibreConnection::withoutGlobalScopes()
            ->where('status', MercadoLibreConnection::STATUS_CONNECTED)
            ->each(function (MercadoLibreConnection $connection) use ($client, $sync): void {
                if (! $this->keepAlive($client, $connection)) {
                    return;
                }

                if ($connection->sync_enabled) {
                    $sync->dispatchAll($connection);
                }
            });

        return self::SUCCESS;
    }

    /**
     * false si la cuenta perdió la autorización (ya quedó needs_reauth). Un
     * error transitorio no frena el sync: cada job reintenta por su cuenta.
     */
    private function keepAlive(MercadoLibreClient $client, MercadoLibreConnection $connection): bool
    {
        try {
            $client->keepAlive($connection);

            return true;
        } catch (MercadoLibreException $e) {
            Log::warning('No se pudo renovar el token de Mercado Libre', [
                'tenant_id' => $connection->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return ! $e->reauth;
        }
    }
}
