<?php

namespace App\Support;

/**
 * Estado mutable del writer de PublicUrlGuard::sizeLimitedWriter(). Un objeto
 * en vez de referencias `use (&$x)` porque el closure se construye en un
 * método estático (sin $this) y se pasa a un test aparte: pasar el estado
 * como valor de retorno explícito es más simple de inspeccionar que capturar
 * variables por referencia a través del límite del método.
 */
class SizeLimitedWriterState
{
    public string $received = '';

    public bool $exceeded = false;

    /** true si CURLOPT_WRITEFUNCTION llegó a invocarse (falso con Http::fake()). */
    public bool $invoked = false;
}
