<?php

namespace App\Services\Flows;

use App\Jobs\EvaluateAutomationEventJob;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Rules\ValidContactCustomData;
use App\Support\ContactCustomDataNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Respuestas de formularios (`interactive.nfm_reply`): las asocia a su sesión,
 * arma el mensaje legible del chat, mapea campos del contacto y dispara el
 * evento de automatización.
 */
class FlowResponseService
{
    private const MAX_CONTENT_LINES = 20;

    public function parse(array $nfmReply, int $whatsappConfigId, ?string $contextMessageId): ParsedFlowResponse
    {
        $decoded = is_string($nfmReply['response_json'] ?? null) ? json_decode($nfmReply['response_json'], true) : null;
        $response = is_array($decoded) ? $decoded : [];
        $session = $this->findSession($whatsappConfigId, $response['flow_token'] ?? null, $contextMessageId);
        $flow = $session?->flow;
        $answers = Arr::except($response, ['flow_token']);

        return new ParsedFlowResponse(
            session: $session,
            response: $answers,
            content: $this->content($flow, $answers),
            interactive: [
                'kind' => 'flow_response',
                'whatsapp_flow_id' => $flow?->id,
                'flow_name' => $flow?->name,
                'session_id' => $session?->id,
                'response' => $answers,
            ],
        );
    }

    public function complete(ParsedFlowResponse $parsed, Message $message, Contact $contact, Conversation $conversation): void
    {
        $session = $parsed->session;

        if ($session === null) {
            return;
        }

        if ($session->status === WhatsAppFlowSession::STATUS_PENDING) {
            $session->update([
                'status' => WhatsAppFlowSession::STATUS_COMPLETED,
                'completed_at' => now(),
                'response' => $parsed->response,
            ]);
        }

        $this->applyFieldMappings($session->flow, $contact, $parsed->response);

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

    private function content(?WhatsAppFlow $flow, array $answers): string
    {
        $lines = ['📝 '.($flow?->name ?? 'Formulario')];

        foreach (array_slice($answers, 0, self::MAX_CONTENT_LINES, true) as $key => $value) {
            $lines[] = "{$key}: ".$this->displayValue($value);
        }

        return implode("\n", $lines);
    }

    private function displayValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'Sí' : 'No',
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $value === null => '',
            default => (string) $value,
        };
    }

    private function applyFieldMappings(WhatsAppFlow $flow, Contact $contact, array $response): void
    {
        $tenantId = (int) $contact->tenant_id;
        $custom = [];

        foreach ($flow->field_mappings ?? [] as $mapping) {
            $value = data_get($response, $mapping['response_key'] ?? '');

            if ($value === null || $value === '') {
                continue;
            }

            $target = (string) ($mapping['target'] ?? '');

            if ($target === 'contact.name' && is_scalar($value)) {
                $contact->name = Str::limit((string) $value, 255, '');
            } elseif ($target === 'contact.email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $contact->email = (string) $value;
            } elseif (str_starts_with($target, 'custom_data.')) {
                $custom[substr($target, strlen('custom_data.'))] = $value;
            }
        }

        if ($custom !== []) {
            $custom = ContactCustomDataNormalizer::normalize($custom, $tenantId);
            $known = ContactField::forTenant($tenantId)->pluck('key')->all();
            $custom = Arr::only($custom, $known);
        }

        if ($custom !== []) {
            $validator = Validator::make(
                ['custom_data' => $custom],
                ['custom_data' => ['array', new ValidContactCustomData($contact->id, array_keys($custom), $tenantId)]],
            );

            if ($validator->fails()) {
                Log::warning('Flow response: campos personalizados no aplicados', [
                    'errors' => $validator->errors()->toArray(),
                    'contact_id' => $contact->id,
                ]);
            } else {
                $contact->custom_data = array_merge($contact->custom_data ?? [], $custom);
            }
        }

        if ($contact->isDirty()) {
            $contact->save();
        }
    }
}
