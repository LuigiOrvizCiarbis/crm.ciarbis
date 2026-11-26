<?php

namespace App\Services\Commerce;

use RuntimeException;

/** Ninguno de los productos pedidos está habilitado todavía en el catálogo de WhatsApp. */
class CatalogItemsUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Esos productos todavía no están habilitados en el catálogo de WhatsApp.')
    {
        parent::__construct($message);
    }
}
