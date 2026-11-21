<?php

namespace App\Services\Ai\Assistant;

use App\Exceptions\AssistantException;
use App\Models\AssistantUsage;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Registra los tokens del asistente por workspace y día, y aplica el cupo
 * diario cuando el servicio lo paga la plataforma.
 */
class AssistantUsageMeter
{
    public function ensureWithinDailyLimit(int $tenantId): void
    {
        $limit = (int) config('services.ai.assistant.daily_token_limit_per_tenant', 0);

        if ($limit <= 0) {
            return;
        }

        $usage = AssistantUsage::query()
            ->where('tenant_id', $tenantId)
            ->where('date', today()->toDateString())
            ->first();

        if ($usage && $usage->input_tokens + $usage->output_tokens >= $limit) {
            throw new AssistantException(
                'El workspace alcanzó el límite diario del asistente. Volvé a intentar mañana.',
                'assistant_daily_limit',
                429,
            );
        }
    }

    public function record(int $tenantId, Usage $usage): void
    {
        AssistantUsage::query()
            ->createOrFirst(['tenant_id' => $tenantId, 'date' => today()->toDateString()])
            ->incrementEach([
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
            ]);
    }
}
