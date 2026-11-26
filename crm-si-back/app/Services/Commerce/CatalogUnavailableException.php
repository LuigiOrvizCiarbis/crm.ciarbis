<?php

namespace App\Services\Commerce;

use RuntimeException;

/** Meta no encuentra los productos del catálogo conectado al número de WhatsApp. */
class CatalogUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('WhatsApp no encuentra los productos del catálogo conectado a este número. Revisá en Commerce Manager que el catálogo esté habilitado para WhatsApp.');
    }
}
