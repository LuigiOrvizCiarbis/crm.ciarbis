<?php

namespace App\Services\Ai\Assistant;

use App\Exceptions\AssistantException;
use App\Models\AssistantUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Registra el consumo del asistente por workspace y día, y aplica los cupos
 * diarios cuando el servicio lo paga la plataforma: tokens para el chat y
 * segundos de audio para el dictado, por separado (un workspace que paga su
 * chat con su propia key no se queda sin dictado por eso, ni al revés).
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

    /**
     * Se controla antes de transcribir, con lo ya consumido: la duración del
     * audio nuevo recién se conoce después. Un dictado puede pasarse del cupo
     * como mucho por su propio largo (el grabador corta a los 5 minutos).
     */
    public function ensureWithinDailyAudioLimit(int $tenantId): void
    {
        $limit = (float) config('services.ai.assistant.transcription.daily_audio_seconds_limit_per_tenant', 0);

        if ($limit <= 0) {
            return;
        }

        $used = (float) (AssistantUsage::query()
            ->where('tenant_id', $tenantId)
            ->where('date', today()->toDateString())
            ->value('audio_seconds') ?? 0);

        if ($used >= $limit) {
            throw new AssistantException(
                'El workspace alcanzó el límite diario de dictado por voz. Volvé a intentar mañana o escribí el mensaje.',
                'transcription_daily_limit',
                429,
            );
        }
    }

    /**
     * Suma lo consumido por un paso del agente o por una transcripción. Los
     * segundos de audio salen de lo que informa el proveedor, salvo que se
     * pasen medidos aparte ($audioSeconds) porque el proveedor no los reporta.
     */
    public function record(int $tenantId, Usage $usage, ?float $audioSeconds = null): void
    {
        $reported = $usage instanceof TranscriptionUsage ? $usage->audioSeconds : null;

        AssistantUsage::query()
            ->createOrFirst(['tenant_id' => $tenantId, 'date' => today()->toDateString()])
            ->incrementEach([
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'audio_seconds' => $audioSeconds ?? $reported ?? 0,
            ]);
    }
}
