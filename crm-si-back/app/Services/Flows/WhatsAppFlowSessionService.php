<?php

namespace App\Services\Flows;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppTemplate;
use App\Services\Flows\Builder\FlowDefinitionCompiler;
use Illuminate\Support\Facades\Log;

/**
 * Sesiones de formulario: cada envío lleva un flow_token propio con el que el
 * endpoint y la respuesta encuentran conversación, contacto y formulario.
 */
class WhatsAppFlowSessionService
{
    private const TTL_DAYS = 30;

    private const PREFILL_KEYS = ['contact_name', 'contact_email', 'contact_phone'];

    /**
     * Un reenvío del mismo formulario al mismo contacto retoma lo que ya había
     * respondido en el envío anterior sin completar.
     */
    public function open(WhatsAppFlow $flow, Conversation $conversation): WhatsAppFlowSession
    {
        return WhatsAppFlowSession::create([
            'tenant_id' => $conversation->tenant_id,
            'whatsapp_flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'flow_token' => bin2hex(random_bytes(24)),
            'status' => WhatsAppFlowSession::STATUS_PENDING,
            'data' => $this->unfinishedProgress($flow, $conversation->contact_id),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);
    }

    /**
     * Datos de una pantalla para el endpoint. En formularios del constructor,
     * cada texto declarado recibe lo ya respondido o, si no hay, su precarga
     * del contacto; el resto usa las claves de precarga de `prefillData`.
     */
    public function screenData(WhatsAppFlowSession $session, string $screenId): array
    {
        $flow = $session->flow;
        $contact = $session->conversation?->contact;
        $index = FlowDefinitionCompiler::screenIndex($screenId);
        $blocks = $index !== null ? ($flow->builder_definition['screens'][$index]['blocks'] ?? null) : null;

        if ($blocks === null) {
            return $this->prefillData($flow, $contact, $screenId);
        }

        $answers = $session->data ?? [];
        $prefill = $this->contactPrefill($contact);
        $data = [];

        foreach ($blocks as $block) {
            if (! in_array($block['type'] ?? null, FlowDefinitionCompiler::RESTORABLE_TYPES, true)) {
                continue;
            }

            $saved = $answers[$block['key']] ?? null;
            $data[$block['key']] = is_scalar($saved) && (string) $saved !== ''
                ? (string) $saved
                : (string) ($prefill[$block['prefill'] ?? ''] ?? '');
        }

        return $data;
    }

    private function unfinishedProgress(WhatsAppFlow $flow, ?int $contactId): ?array
    {
        if ($contactId === null) {
            return null;
        }

        return WhatsAppFlowSession::withoutGlobalScopes()
            ->where('whatsapp_flow_id', $flow->id)
            ->where('contact_id', $contactId)
            ->where('status', WhatsAppFlowSession::STATUS_PENDING)
            ->whereNotNull('data')
            ->latest('id')
            ->value('data');
    }

    /**
     * Si el template tiene un botón FLOW, abre una sesión y agrega el
     * componente del botón con su flow_token. Sin formulario local, Meta usa
     * el token por defecto (`unused`) y la respuesta se asocia por context.id.
     * Con `navigate`, la precarga del contacto viaja en `flow_action_data`;
     * con `data_exchange` la pide el formulario al endpoint (INIT).
     *
     * @return array{0: array, 1: ?WhatsAppFlowSession}
     */
    public function attachToTemplate(Conversation $conversation, WhatsAppTemplate $template, array $components): array
    {
        $button = $template->flowButton();

        if ($button === null || collect($components)->contains(fn ($c) => ($c['sub_type'] ?? null) === 'flow')) {
            return [$components, null];
        }

        $query = WhatsAppFlow::withoutGlobalScopes()->where('whatsapp_config_id', $template->whatsapp_config_id);
        $flow = $button['flow_id'] !== null
            ? $query->where('meta_flow_id', $button['flow_id'])->first()
            : $query->where('name', $button['flow_name'])->first();

        if ($flow === null) {
            Log::warning('Template con botón FLOW sin formulario local', [
                'template_id' => $template->id,
                'flow_id' => $button['flow_id'],
                'flow_name' => $button['flow_name'],
            ]);

            return [$components, null];
        }

        $session = $this->open($flow, $conversation);
        $action = ['flow_token' => $session->flow_token];
        $prefill = $button['flow_action'] === 'navigate' ? $this->templatePrefill($flow, $conversation, $button['navigate_screen']) : [];
        if ($prefill !== []) {
            $action['flow_action_data'] = $prefill;
        }

        $components[] = [
            'type' => 'button',
            'sub_type' => 'flow',
            'index' => (string) $button['index'],
            'parameters' => [['type' => 'action', 'action' => $action]],
        ];

        return [$components, $session];
    }

    /** Precarga de la pantalla de entrada; vacía si no se conoce qué declara. */
    private function templatePrefill(WhatsAppFlow $flow, Conversation $conversation, ?string $screenId): array
    {
        $screenId ??= $flow->entryScreen();

        if ($screenId === null || $flow->declaredDataKeys($screenId) === null) {
            return [];
        }

        return $this->prefillData($flow, $conversation->contact, $screenId);
    }

    /**
     * Datos de precarga del contacto: `contact_name`, `contact_email`,
     * `contact_phone` y `cf_<key>` por campo personalizado. Si se conoce la
     * pantalla, se limita a lo que declara y completa con "" las claves de
     * precarga que falten, porque Meta exige recibir todos los datos
     * declarados. Otras claves declaradas (opciones de turno) las agrega
     * quien arma la respuesta.
     */
    public function prefillData(WhatsAppFlow $flow, ?Contact $contact, string $screenId): array
    {
        $data = $this->contactPrefill($contact);
        $declared = $flow->declaredDataKeys($screenId);

        if ($declared === null) {
            return $data;
        }

        $filtered = [];
        foreach ($declared as $key) {
            if (array_key_exists($key, $data)) {
                $filtered[$key] = $data[$key];
            } elseif (in_array($key, self::PREFILL_KEYS, true) || str_starts_with($key, 'cf_')) {
                $filtered[$key] = '';
            }
        }

        return $filtered;
    }

    /** @return array<string, mixed> claves de precarga del contacto, sin valores nulos */
    private function contactPrefill(?Contact $contact): array
    {
        if ($contact === null) {
            return [];
        }

        $data = [
            'contact_name' => $contact->name,
            'contact_email' => $contact->email,
            'contact_phone' => $contact->phone,
        ];

        foreach ($contact->custom_data ?? [] as $key => $value) {
            $data['cf_'.$key] = $value;
        }

        return array_filter(
            array_map(fn ($value) => is_scalar($value) ? (string) $value : $value, $data),
            fn ($value) => $value !== null,
        );
    }
}
