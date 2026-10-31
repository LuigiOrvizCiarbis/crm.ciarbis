<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Para tests que verifican el comportamiento de columnas timestamptz (el bug
 * de timezone del incidente de difusiones). SQLite, que usa la suite por
 * defecto, no distingue timestamp de timestamptz — un test así "pasaría" sin
 * probar nada. Este trait saltea en vez de dejar pasar un falso verde (la
 * suite por defecto ya excluye el grupo requires-postgres en phpunit.xml).
 *
 * Correr con: php artisan test -c phpunit.postgres.xml --group=requires-postgres
 */
trait RequiresPostgresTimestamptz
{
    protected function setUp(): void
    {
        parent::setUp();

        $driver = DB::connection()->getDriverName();

        if ($driver !== 'pgsql') {
            $this->markTestSkipped(
                "Este test necesita Postgres real (conexión actual: '{$driver}'). "
                .'SQLite no distingue timestamp de timestamptz y no puede validar esto. '
                .'Correr con: php artisan test -c phpunit.postgres.xml --group=requires-postgres'
            );
        }

        // pgsql_testing no corre migrate:fresh entre corridas por defecto;
        // asegurar el esquema al vuelo si falta.
        if (! Schema::hasTable('broadcast_campaigns')) {
            $this->artisan('migrate', ['--force' => true])->run();
        }
    }
}
