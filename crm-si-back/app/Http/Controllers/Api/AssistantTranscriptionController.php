<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AssistantException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssistantTranscriptionRequest;
use App\Services\Ai\Assistant\AssistantProviderResolver;
use App\Services\Ai\Assistant\AssistantUsageMeter;
use App\Services\AudioDuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Transcription;
use Throwable;

/**
 * Dictado por voz del asistente: transcribe el audio y devuelve el texto para
 * que el usuario lo revise en el campo antes de enviarlo. No crea mensajes.
 */
class AssistantTranscriptionController extends Controller
{
    private const LANGUAGES = ['es', 'en'];

    public function __invoke(
        AssistantTranscriptionRequest $request,
        AssistantProviderResolver $resolver,
        AssistantUsageMeter $meter,
        AudioDuration $duration,
    ): JsonResponse {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;

        try {
            $provider = $resolver->resolveTranscription($tenantId);
            // Cupo propio en segundos de audio, no el de tokens del chat: los
            // proveedores de transcripción informan 0 tokens, y el chat puede
            // estar pagándolo el workspace con su propia key.
            if ($provider->metered) {
                $meter->ensureWithinDailyAudioLimit($tenantId);
            }
        } catch (AssistantException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        $locale = $user->preferencesWithDefaults()['locale'] ?? 'es';

        try {
            $transcript = Transcription::fromUpload($request->file('audio'))
                ->language(in_array($locale, self::LANGUAGES, true) ? $locale : 'es')
                ->timeout((int) config('services.ai.assistant.transcription.timeout', 60))
                ->generate([Ai::build($provider->config)]);
        } catch (Throwable $e) {
            Log::warning('Assistant transcription failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'No se pudo transcribir el audio. Probá de nuevo.',
                'code' => 'transcription_failed',
            ], 502);
        }

        // Si el proveedor no informa la duración, se mide: sin eso el cupo de
        // audio de lo que pagamos nunca avanzaría.
        $measured = $provider->metered && $transcript->usage->audioSeconds === null
            ? $duration->seconds($request->file('audio')->getRealPath())
            : null;
        $meter->record($tenantId, $transcript->usage, $measured);
        $text = trim($transcript->text);

        if ($text === '') {
            return response()->json([
                'message' => 'No se entendió el audio. Probá de nuevo, más cerca del micrófono.',
                'code' => 'transcription_empty',
            ], 422);
        }

        return response()->json(['data' => ['text' => $text]]);
    }
}
