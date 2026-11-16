<?php

namespace App\Services\Flows;

use InvalidArgumentException;

/** Meta rechazó el nombre: ya lo usa otro formulario de la cuenta de WhatsApp Business. */
class FlowNameTakenException extends InvalidArgumentException {}
