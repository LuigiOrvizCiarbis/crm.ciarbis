<?php

namespace App\Services;

use App\Models\AiConfig;

class AiReplyEligibility
{
    public function hasUsableConfig(int $tenantId): bool
    {
        $config = AiConfig::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->first();

        return (bool) ($config && $config->enabled && $config->getDecryptedApiKey());
    }
}
