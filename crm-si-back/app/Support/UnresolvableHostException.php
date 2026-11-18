<?php

namespace App\Support;

/**
 * El dominio no resolvió a ninguna IP. A diferencia del resto de los rechazos
 * de PublicUrlGuard puede ser transitorio (falla de DNS), así que quien
 * reintenta puede distinguirlo.
 */
class UnresolvableHostException extends PublicUrlRejectedException {}
