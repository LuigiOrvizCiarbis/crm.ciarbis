<?php

namespace App\Services\Commerce;

use RuntimeException;

/**
 * Error de la compra por WhatsApp. El mensaje es apto para mostrarlo al cliente.
 */
class CheckoutException extends RuntimeException {}
