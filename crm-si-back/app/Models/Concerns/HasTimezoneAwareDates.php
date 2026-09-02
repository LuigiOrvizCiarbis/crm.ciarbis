<?php

namespace App\Models\Concerns;

/**
 * Para modelos con columnas timestamptz.
 *
 * Sin esto, Eloquent persiste fechas con el formato 'Y-m-d H:i:s' (sin
 * offset). Con la sesión de Postgres en UTC y PHP en America/Argentina, un
 * now() local se guarda como si ya fuera UTC: se pierden 3 horas en cada
 * escritura. Incluir el offset ('P') hace que Postgres interprete el instante
 * correctamente sea cual sea el origen del Carbon (now() local, ->utc(), o un
 * ISO con Z parseado del front).
 *
 * No usar en modelos con columnas timestamp SIN zona (la mayoría del
 * sistema): esas guardan hora local de forma consistente y no necesitan esto.
 */
trait HasTimezoneAwareDates
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:sP';
    }
}
