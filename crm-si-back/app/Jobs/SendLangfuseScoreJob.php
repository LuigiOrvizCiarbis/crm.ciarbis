<?php

namespace App\Jobs;

use App\Models\AiMessageFeedback;
use App\Models\AssistantMessageFeedback;
use App\Services\Observability\Langfuse\LangfuseNames;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Envía a Langfuse la calificación 👍/👎 de un operador como score booleano.
 *
 * El id es determinístico y el timestamp es el de la primera calificación:
 * Langfuse actualiza un score cuando llegan el mismo id y el mismo timestamp,
 * así un cambio de voto lo sobrescribe en vez de duplicarlo.
 * El voto se lee al ejecutar (el modelo se recarga al deserializar), no al
 * encolar: un reintento atrasado nunca pisa un voto más nuevo.
 *
 * La API recibe una lista de scores y responde 202 si los aceptó todos o
 * 207 con los rechazados. Un 207 es un rechazo de validación: el job falla
 * sin reintentar, porque reenviar el mismo score no lo arregla.
 */
class SendLangfuseScoreJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    // Si la calificación ya no existe no hay nada que enviar.
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string  $subject  Lo calificado (p. ej. "ai-msg-15"); forma el id del score.
     */
    public function __construct(
        public AiMessageFeedback|AssistantMessageFeedback $feedback,
        public string $subject,
        public string $traceId,
        public ?string $observationId,
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $publicKey = config('langfuse.public_key');
        $secretKey = config('langfuse.secret_key');

        if (! config('langfuse.enabled') || blank($publicKey) || blank($secretKey)) {
            return;
        }

        $response = Http::withBasicAuth($publicKey, $secretKey)
            ->timeout((float) config('langfuse.timeout'))
            ->acceptJson()
            ->post(config('langfuse.host').'/api/public/scores', [$this->payload()])
            ->throw();

        if ((int) $response->json('rejected', 0) > 0) {
            $this->fail(new RuntimeException('Langfuse rechazó el score: '.json_encode($response->json('errors'))));
        }
    }

    /**
     * Incluye el entorno: si staging y producción comparten proyecto de
     * Langfuse, los ids autoincrementales de cada uno no se pisan.
     */
    public function scoreId(): string
    {
        return 'crm-'.config('langfuse.environment').'-'.$this->subject.'-u'.$this->feedback->user_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $payload = [
            'id' => $this->scoreId(),
            'traceId' => $this->traceId,
            'observationId' => $this->observationId,
            'name' => LangfuseNames::USER_FEEDBACK,
            'value' => $this->feedback->rating === $this->feedback::UP ? 1 : 0,
            'dataType' => 'BOOLEAN',
            'timestamp' => $this->feedback->created_at->toIso8601ZuluString(),
            // El mismo entorno que los traces, para filtrar scores por entorno.
            'environment' => config('langfuse.environment'),
        ];

        // Sin observación el score queda a nivel trace.
        if ($this->observationId === null) {
            unset($payload['observationId']);
        }

        return $payload;
    }
}
