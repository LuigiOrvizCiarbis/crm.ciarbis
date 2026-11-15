<?php

namespace App\Services\Templates;

use RuntimeException;

/**
 * Un parámetro de plantilla no se pudo resolver. El mensaje es un código
 * auditable (`missing_parameter:<nombre>`, `media_asset_not_found`,
 * `header_url_not_accessible`) que la automatización guarda tal cual.
 */
class TemplateParameterException extends RuntimeException {}
