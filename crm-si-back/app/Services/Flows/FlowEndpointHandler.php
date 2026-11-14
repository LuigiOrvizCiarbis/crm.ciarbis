<?php

namespace App\Services\Flows;

use App\Models\WhatsAppConfig;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Services\Flows\Builder\FlowDefinitionCompiler;
use App\Services\Flows\Builder\FlowDefinitionRoutes;
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
        private readonly FlowContactUpdater $contactUpdater,
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
            return FlowEndpointResult::invalidToken($session?->status === WhatsAppFlowSession::STATUS_SUPERSEDED
                ? 'Te enviamos una versión más nueva de este formulario. Abrila desde el último mensaje.'
                : 'Este formulario ya no está disponible. Pedinos uno nuevo por el chat.');
        }

        $data = is_array($request['data'] ?? null) ? $request['data'] : [];
        $screen = is_string($request['screen'] ?? null) ? $request['screen'] : null;

        if (! in_array($action, ['INIT', 'BACK'], true)) {
            $session->data = array_merge($session->data ?? [], Arr::except($data, self::ROUTING_KEYS));
            $session->save();
        }

        $flow = $session->flow;
        $entry = $flow->liveVersion()->entryScreen() ?? self::FALLBACK_SCREEN;

        return FlowEndpointResult::payload(match ($action) {
            'INIT' => $this->screenResponse($flow, $entry, $this->sessions->screenData($session, $entry)),
            'BACK' => $this->screenResponse($flow, $screen ?? $entry, $this->sessions->screenData($session, $screen ?? $entry)),
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
                FlowDefinitionCompiler::SUBMIT_ACTION => $this->submitScreen($session, $screen, $next, Arr::except($data, self::ROUTING_KEYS)),
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

    /**
     * Pantalla del constructor: guarda en el contacto lo que pasa las reglas
     * del CRM y, si algo no las pasa, devuelve la misma pantalla con el aviso.
     * El camino lo decide la versión que corre el cliente según lo que respondió.
     */
    private function submitScreen(WhatsAppFlowSession $session, string $screen, ?string $next, array $answers): array
    {
        $contact = $session->conversation?->contact;
        $version = $session->flow->liveVersion();
        $definition = $version->runtimeDefinition;
        // Un grupo repetible se guarda entero al terminarlo: necesita las filas
        // de las pantallas anteriores del recorrido, no sólo las de esta.
        $context = array_merge($this->sessions->answersBefore($session, $screen), $answers);
        $destination = $definition !== null ? $this->builderNext($definition, $screen, $answers) : $next;
        ['saved' => $saved, 'errors' => $errors] = $contact !== null
            ? $this->contactUpdater->apply($session->flow, $contact, $answers, $context, $this->leavesGroups($version, $screen, $destination))
            : ['saved' => [], 'errors' => []];

        if ($errors !== []) {
            return $this->errorResponse($session, $screen, implode(' ', $errors));
        }

        if ($definition !== null) {
            $this->sessions->recordProgress($session, $screen, $saved);
            $next = $destination;
        }

        return match ($next) {
            null => $this->complete($session, []),
            FlowDefinitionCompiler::DATE_SCREEN => $this->navigate($session, $next, [
                'available_dates' => $this->appointments->dates($session->conversation),
            ]),
            default => $this->navigate($session, $next, []),
        };
    }

    /** Pantalla siguiente del constructor; null cierra el formulario. */
    private function builderNext(array $definition, string $screen, array $answers): ?string
    {
        $index = FlowDefinitionCompiler::screenIndex($screen);
        $destination = $index !== null ? FlowDefinitionRoutes::fromDefinition($definition)->next($index, $answers) : null;

        if ($destination !== null) {
            return FlowDefinitionCompiler::screenId($destination);
        }

        return ($definition['appointment'] ?? null) !== null ? FlowDefinitionCompiler::DATE_SCREEN : null;
    }

    /**
     * Grupos repetibles que se terminan al pasar de `$screen` a `$next`: la
     * pantalla enviada es del grupo y la siguiente ya no (o el formulario termina).
     *
     * @return list<string> claves de los grupos
     */
    private function leavesGroups(FlowVersion $version, string $screen, ?string $next): array
    {
        $screens = $version->runtimeDefinition['screens'] ?? [];
        $from = $screens[FlowDefinitionCompiler::screenIndex($screen) ?? -1]['repeater'] ?? null;
        $to = $next !== null ? ($screens[FlowDefinitionCompiler::screenIndex($next) ?? -1]['repeater'] ?? null) : null;

        return is_string($from) && $from !== $to ? [$from] : [];
    }

    private function bookAppointment(WhatsAppFlowSession $session, array $data): array
    {
        $answers = $session->data ?? [];
        $version = $session->flow->liveVersion();
        $emailKey = collect($version->fieldMappings)->firstWhere('target', 'contact.email')['response_key'] ?? null;
        $reasonKey = $version->definition['appointment']['reason_key'] ?? null;

        $task = $this->appointments->book(
            $session->conversation,
            (string) ($data['slot'] ?? ''),
            $this->optionalString($data['appointment_email'] ?? ($emailKey !== null ? $answers[$emailKey] ?? null : null)),
            $this->optionalString($data['appointment_reason'] ?? ($reasonKey !== null ? $answers[$reasonKey] ?? null : null)),
        );

        return $this->complete($session, [
            'appointment_id' => $task->id,
            'appointment_start' => $task->starts_at?->toIso8601String(),
            'appointment_status' => FlowAppointmentProvider::bookingStatus($task),
        ]);
    }

    private function navigate(WhatsAppFlowSession $session, string $screen, array $extra): array
    {
        return $this->screenResponse($session->flow, $screen, array_merge($this->sessions->screenData($session, $screen), $extra));
    }

    private function complete(WhatsAppFlowSession $session, array $extra): array
    {
        return [
            'screen' => 'SUCCESS',
            'data' => [
                'extension_message_response' => [
                    'params' => ['flow_token' => $session->flow_token] + $this->sessions->pathAnswers($session) + $extra,
                ],
            ],
        ];
    }

    private function errorResponse(WhatsAppFlowSession $session, string $screen, string $message): array
    {
        return $this->screenResponse(
            $session->flow,
            $screen,
            ['error_message' => $message] + $this->appointmentOptions($session, $screen) + $this->sessions->screenData($session, $screen),
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
        $declared = $flow->liveVersion()->declaredDataKeys($screenId);

        if ($declared !== null) {
            $data = Arr::only($data, [...$declared, 'error_message']);
        }

        return ['screen' => $screenId, 'data' => $data === [] ? new \stdClass : $data];
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
