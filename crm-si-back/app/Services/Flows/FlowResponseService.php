<?php

namespace App\Services\Flows;

use App\Events\MessageEdited;
use App\Jobs\EvaluateAutomationEventJob;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Respuestas de formularios (`interactive.nfm_reply`): las asocia a su sesión,
 * arma el mensaje legible del chat, mapea campos del contacto y dispara el
 * evento de automatización.
 */
class FlowResponseService
{
    private const MAX_CONTENT_LINES = 20;

    /** Datos de la reserva que agrega el endpoint: no son preguntas del formulario. */
    private const APPOINTMENT_LABELS = ['appointment_start' => 'Turno', 'appointment_status' => 'Estado del turno'];

    private const APPOINTMENT_STATUSES = ['confirmed' => 'confirmado', 'pending' => 'pendiente de confirmación'];

    public function __construct(
        private readonly FlowContactUpdater $contactUpdater,
        private readonly WhatsAppFlowSessionService $sessions,
    ) {}

    /**
     * Las respuestas de pantallas que pasaron por el endpoint ya están en la
     * sesión; la última pantalla puede cerrar sin endpoint y traer sólo las
     * suyas, así que se combinan con las del recorrido.
     */
    public function parse(array $nfmReply, int $whatsappConfigId, ?string $contextMessageId): ParsedFlowResponse
    {
        $decoded = is_string($nfmReply['response_json'] ?? null) ? json_decode($nfmReply['response_json'], true) : null;
        $response = is_array($decoded) ? $decoded : [];
        $session = $this->findSession($whatsappConfigId, $response['flow_token'] ?? null, $contextMessageId);
        $flow = $session?->flow;
        $answers = array_merge($session !== null ? $this->sessions->pathAnswers($session) : [], Arr::except($response, ['flow_token']));
        $labels = array_intersect_key($flow?->liveVersion()->questionLabels() ?? [], $answers);

        return new ParsedFlowResponse(
            session: $session,
            response: $answers,
            content: $this->content($flow, $answers, $labels),
            interactive: [
                'kind' => 'flow_response',
                'whatsapp_flow_id' => $flow?->id,
                'flow_name' => $flow?->name,
                'session_id' => $session?->id,
                'response' => $answers,
                'labels' => $labels,
            ],
        );
    }

    public function complete(ParsedFlowResponse $parsed, Message $message, Contact $contact, Conversation $conversation): void
    {
        $session = $parsed->session;

        if ($session === null) {
            return;
        }

        // Un envío reemplazado que no usa el endpoint se puede responder igual.
        if (in_array($session->status, [WhatsAppFlowSession::STATUS_PENDING, WhatsAppFlowSession::STATUS_SUPERSEDED], true)) {
            $this->sessions->complete($session, $parsed->response);
        }

        $mappingErrors = $this->contactUpdater->apply($session->flow, $contact, $parsed->response)['errors'];

        // Las pantallas con endpoint ya se validaron; la última puede cerrar sin
        // endpoint, o un valor único pudo ocuparse entre medio: el agente lo ve.
        if ($mappingErrors !== []) {
            Log::warning('Flow response: respuestas no guardadas en el contacto', [
                'contact_id' => $contact->id,
                'errors' => $mappingErrors,
            ]);
            $message->update(['interactive' => [...($message->interactive ?? []), 'mapping_errors' => $mappingErrors]]);

            try {
                event(new MessageEdited($message));
            } catch (\Throwable $e) {
                Log::warning('No se pudo emitir el aviso de respuestas no guardadas', ['message_id' => $message->id, 'error' => $e->getMessage()]);
            }
        }

        EvaluateAutomationEventJob::dispatch($conversation->tenant_id, [
            'id' => (string) Str::uuid(),
            'type' => 'whatsapp_flow.response_received',
            'subject_type' => 'conversation',
            'subject_id' => $conversation->id,
            'whatsapp_flow_id' => $session->flow->id,
            'message_id' => $message->id,
            'response' => $parsed->response,
            'occurred_at' => now()->toIso8601String(),
        ])->afterCommit();
    }

    /**
     * Por token; si no aparece (p. ej. plantilla enviada con el token por
     * defecto `unused`), por el wamid del mensaje del formulario.
     */
    private function findSession(int $whatsappConfigId, mixed $token, ?string $contextMessageId): ?WhatsAppFlowSession
    {
        $query = fn () => WhatsAppFlowSession::withoutGlobalScopes()
            ->whereHas('flow', fn ($q) => $q->withoutGlobalScopes()->where('whatsapp_config_id', $whatsappConfigId))
            ->with(['flow' => fn ($q) => $q->withoutGlobalScopes()]);

        $session = is_string($token) && $token !== ''
            ? $query()->where('flow_token', $token)->first()
            : null;

        if ($session === null && $contextMessageId !== null) {
            $session = $query()
                ->whereHas('message', fn ($q) => $q->withoutGlobalScopes()->where('external_id', $contextMessageId))
                ->latest('id')
                ->first();
        }

        return $session;
    }

    /**
     * Resumen legible para la vista previa de la conversación y la IA.
     *
     * @param  array<string, string>  $labels
     */
    private function content(?WhatsAppFlow $flow, array $answers, array $labels): string
    {
        $lines = ['📝 '.($flow?->name ?? 'Formulario')];

        foreach (Arr::except($answers, ['appointment_id']) as $key => $value) {
            $lines[] = ($labels[$key] ?? self::APPOINTMENT_LABELS[$key] ?? $key).': '.$this->displayValue($key, $value);
        }

        return implode("\n", array_slice($lines, 0, self::MAX_CONTENT_LINES + 1));
    }

    private function displayValue(string $key, mixed $value): string
    {
        return match (true) {
            $key === 'appointment_start' && is_string($value) => rescue(fn () => Carbon::parse($value)->format('d/m/Y H:i'), $value, false),
            $key === 'appointment_status' && is_string($value) => self::APPOINTMENT_STATUSES[$value] ?? $value,
            is_bool($value) => $value ? 'Sí' : 'No',
            is_array($value) => implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : (string) json_encode($item, JSON_UNESCAPED_UNICODE), $value)),
            $value === null => '',
            default => (string) $value,
        };
    }
}
