<?php

namespace App\Support;

/**
 * Se lanza cuando una URL controlada por un tenant/contacto no es una URL
 * pública válida (esquema no http/https, puerto no estándar, resuelve a una
 * IP privada/loopback/link-local, redirige a un host no público, o excede
 * los límites de fetch). Protege contra SSRF.
 */
class PublicUrlRejectedException extends \RuntimeException {}
