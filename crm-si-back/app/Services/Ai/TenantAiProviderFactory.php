<?php

namespace App\Services\Ai;

use App\Models\AiConfig;

/**
 * Wrapper inyectable de AiProviderFactory para los servicios que usan la key
 * BYOK del tenant (traducciones, información del sitio). Permite reemplazar
 * el driver en tests.
 */
class TenantAiProviderFactory
{
    public function make(AiConfig $config): ?AiProvider
    {
        return AiProviderFactory::make($config);
    }
}
