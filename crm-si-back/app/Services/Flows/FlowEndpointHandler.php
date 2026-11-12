<?php

namespace App\Services\Flows;

use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Services\Flows\Builder\FlowDefinitionCompiler;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Lógica del endpoint de datos de WhatsApp Flows sobre el request ya
 * descifrado. El formulario elige el proveedor con `crm_action` y la pantalla
 * destino con `next_screen` en el payload de `data_exchange`.
 */
class FlowEndpointHandler
{
    private const ROUTING_KEYS = ['crm_action', 'next_screen'];

    private const FALLBACK_SCREEN = 'FIRST_ENTRY_SCREEN';

    public function __construct(
        private readonly WhatsAppFlowSessionService $sessions,
        private readonly FlowAppointmentProvider $appointments,
    ) {}

    public function handle(WhatsAppConfig $config, array $request): FlowEndpointResult
    {
        $action = $request['action'] ?? null;

        if ($action === 'ping') {
            return FlowEndpointResult::payload(['data' => ['status' => 'active']]);
        }

        if (isset($request['data']['error'])) {
            Log::warning('Flow endpoint: error del cliente', [
                'config_id' => $config->id,
                'screen' => $request['screen'] ?? null,
                'error' => $request['data']['error'],
                'error_message' => $request['data']['error_message'] ?? null,
            ]);

            return FlowEndpointResult::payload(['data' => ['acknowledged' => true]]);
        }

        $session = $this->findSession($config, (string) ($request['flow_token'] ?? ''));

        if ($session === null || ! $session->isUsable()) {
            return FlowEndpointResult::invalidToken('Este formulario ya no está disponible. Pedinos uno nuevo por el chat.');
        }

        $data = is_array($request['data'] ?? null) ? $request['data'] : [];
        $screen = is_string($request['screen'] ?? null) ? $request['screen'] : null;

        if (! in_array($action, ['INIT', 'BACK'], true)) {
            $session->data = array_merge($session->data ?? [], Arr::except($data, self::ROUTING_KEYS));
            $session->save();
        }

        $flow = $session->flow;
        $entry = $flow->entryScreen() ?? self::FALLBACK_SCREEN;

        return FlowEndpointResult::payload(match ($action) {
            'INIT' => $this->screenResponse($flow, $entry, $this->prefill($session, $entry)),
            'BACK' => $this->screenResponse($flow, $screen ?? $entry, $this->prefill($session, $screen ?? $entry)),
            'data_exchange' => $this->dataExchange($session, $screen ?? $entry, $data),
            default => $this->errorResponse($session, $screen ?? $entry, 'Acción no soportada.'),
        });
    }

    private function dataExchange(WhatsAppFlowSession $session, string $screen, array $data): array
    {
        $conversation = $session->conversation;
        $next = is_string($data['next_screen'] ?? null) && $data['next_screen'] !== '' ? $data['next_screen'] : null;
        $action = $data['crm_action'] ?? null;

        if (in_array($action, [null, 'prefill', 'appointment_dates', 'appointment_slots'], true) && $next === null) {
            Log::warning('Flow endpoint: data_exchange sin next_screen', [
                'session_id' => $session->id,
                'screen' => $screen,
                'crm_action' => $action,
            ]);

            return $this->errorResponse($session, $screen, 'Configuración del formulario incompleta.');
        }

        try {
            return match ($action) {
                null, 'prefill' => $this->navigate($session, $next, []),
                'appointment_dates' => $this->navigate($session, $next, [
                    'available_dates' => $this->appointments->dates($conversation),
                ]),
                'appointment_slots' => $this->navigate($session, $next, [
                    'available_slots' => $this->appointments->slots($conversation, (string) ($data['date'] ?? '')),
                    'selected_date' => (string) ($data['date'] ?? ''),
                ]),
                'book_appointment' => $this->bookAppointment($session, $data),
                'complete' => $this->complete($session, []),
                default => $this->errorResponse($session, $screen, 'Acción no soportada.'),
            };
        } catch (FlowProviderException $e) {
            return $this->errorResponse($session, $screen, $e->getMessage());
        }
    }

    private function bookAppointment(WhatsAppFlowSession $session, array $data): array
    {
        $task = $this->appointments->book(
            $session->conversation,
            (string) ($data['slot'] ?? ''),
            $this->optionalString($data['appointment_email'] ?? null),
            $this->optionalString($data['appointment_reason'] ?? null),
        );

        return $this->complete($session, [
            'appointment_id' => $task->id,
            'appointment_start' => $task->starts_at?->toIso8601String(),
            'appointment_status' => FlowAppointmentProvider::bookingStatus($task),
        ]);
    }

    private function navigate(WhatsAppFlowSession $session, string $screen, array $extra): array
    {
        return $this->screenResponse($session->flow, $screen, array_merge($this->prefill($session, $screen), $extra));
    }

    private function complete(WhatsAppFlowSession $session, array $extra): array
    {
        return [
            'screen' => 'SUCCESS',
            'data' => [
                'extension_message_response' => [
                    'params' => ['flow_token' => $session->flow_token] + ($session->data ?? []) + $extra,
                ],
            ],
        ];
    }

    private function errorResponse(WhatsAppFlowSession $session, string $screen, string $message): array
    {
        return $this->screenResponse(
            $session->flow,
            $screen,
            ['error_message' => $message] + $this->appointmentOptions($session, $screen) + $this->prefill($session, $screen),
        );
    }

    /**
     * Al volver a mostrar con error una pantalla de turno, se reenvían sus
     * opciones: si no, el desplegable queda vacío y el cliente no puede
     * elegir otra fecha u horario.
     */
    private function appointmentOptions(WhatsAppFlowSession $session, string $screen): array
    {
        try {
            return match ($screen) {
                FlowDefinitionCompiler::DATE_SCREEN => [
                    'available_dates' => $this->appointments->dates($session->conversation),
                ],
                FlowDefinitionCompiler::SLOT_SCREEN => [
                    'available_slots' => $this->appointments->slots($session->conversation, (string) ($session->data['date'] ?? '')),
                    'selected_date' => (string) ($session->data['date'] ?? ''),
                ],
                default => [],
            };
        } catch (FlowProviderException) {
            return [];
        }
    }

    /**
     * Respuesta de pantalla con sólo los datos que la pantalla declara (más
     * `error_message`): Meta rechaza claves no declaradas.
     */
    private function screenResponse(WhatsAppFlow $flow, string $screenId, array $data): array
    {
        $declared = $flow->declaredDataKeys($screenId);

        if ($declared !== null) {
            $data = Arr::only($data, [...$declared, 'error_message']);
        }

        return ['screen' => $screenId, 'data' => $data === [] ? new \stdClass : $data];
    }

    private function prefill(WhatsAppFlowSession $session, string $screen): array
    {
        return $this->sessions->prefillData($session->flow, $session->conversation?->contact, $screen);
    }

    private function findSession(WhatsAppConfig $config, string $token): ?WhatsAppFlowSession
    {
        if ($token === '') {
            return null;
        }

        return WhatsAppFlowSession::withoutGlobalScopes()
            ->where('flow_token', $token)
            ->whereHas('flow', fn ($query) => $query->withoutGlobalScopes()->where('whatsapp_config_id', $config->id))
            ->with([
                'flow' => fn ($query) => $query->withoutGlobalScopes(),
                'conversation' => fn ($query) => $query->withoutGlobalScopes(),
                'conversation.contact' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->first();
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
