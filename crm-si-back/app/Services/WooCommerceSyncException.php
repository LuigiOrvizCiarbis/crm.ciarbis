<?php

namespace App\Services;

/**
 * Se lanza cuando la tienda WooCommerce no devuelve datos válidos durante un
 * sync (credenciales rechazadas, error HTTP, catálogo fuera de límites). El
 * mensaje es apto para mostrarse al usuario.
 */
class WooCommerceSyncException extends \RuntimeException {}
