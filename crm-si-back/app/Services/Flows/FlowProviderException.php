<?php

namespace App\Services\Flows;

use RuntimeException;

/**
 * Error de un proveedor de datos del endpoint, con mensaje para el cliente.
 */
class FlowProviderException extends RuntimeException {}
