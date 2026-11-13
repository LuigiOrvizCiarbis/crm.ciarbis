<?php

namespace App\Services\Flows;

use App\Enums\MessageType;
use App\Events\MessageEdited;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppFlow;
use App\Models\WhatsAppFlowSession;
use App\Models\WhatsAppTemplate;
use App\Services\Flows\Builder\FlowDefinitionCompiler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        $previous = $this->unfinished($flow, $conversation->contact_id)->latest('id')->first();

        return WhatsAppFlowSession::create([
            'tenant_id' => $conversation->tenant_id,
            'whatsapp_flow_id' => $flow->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'flow_token' => bin2hex(random_bytes(24)),
            'status' => WhatsAppFlowSession::STATUS_PENDING,
            'data' => $previous?->data,
            'progress' => $previous?->progress,
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);
    }

    /**
     * Vincula la sesión con el mensaje ya enviado y cierra los envíos
     * anteriores del mismo formulario al contacto: así no quedan dos copias
     * abiertas. Se hace después de enviar para no cerrar nada si el envío falla.
     * Sólo se cierran sesiones más viejas: con dos envíos superpuestos queda
     * abierto el último, sin importar cuál termina de enviarse primero.
     */
    public function activate(WhatsAppFlowSession $session, Message $message): void
    {
        $session->update(['message_id' => $message->id]);

        if ($session->refresh()->status === WhatsAppFlowSession::STATUS_SUPERSEDED) {
            $this->updateMessage($session, ['session_status' => WhatsAppFlowSession::STATUS_SUPERSEDED]);
        }

        $this->unfinished($session->flow, $session->contact_id)
            ->where('id', '<', $session->id)
            ->get()
            ->each(function (WhatsAppFlowSession $previous): void {
                $previous->update(['status' => WhatsAppFlowSession::STATUS_SUPERSEDED]);
                $this->updateMessage($previous, ['session_status' => WhatsAppFlowSession::STATUS_SUPERSEDED]);
            });
    }

    /**
     * Marca la sesión como respondida. Un envío reemplazado que no usa el
     * endpoint se puede responder igual: entonces se cierran también los
     * envíos pendientes del mismo formulario al contacto, que ya no hacen falta.
     */
    public function complete(WhatsAppFlowSession $session, array $response): void
    {
        $session->update([
            'status' => WhatsAppFlowSession::STATUS_COMPLETED,
            'completed_at' => now(),
            'response' => $response,
        ]);
        $this->updateMessage($session, ['session_status' => WhatsAppFlowSession::STATUS_COMPLETED]);

        $this->unfinished($session->flow, $session->contact_id)
            ->get()
            ->each(function (WhatsAppFlowSession $other): void {
                $other->update(['status' => WhatsAppFlowSession::STATUS_COMPLETED, 'completed_at' => now()]);
                $this->updateMessage($other, ['session_status' => WhatsAppFlowSession::STATUS_COMPLETED]);
            });
    }

    /**
     * Registra una pantalla enviada: el recorrido (si el cliente volvió atrás
     * y cambió una respuesta, se descarta lo que seguía) y los campos guardados
     * en el contacto. El mensaje del chat muestra el avance al agente.
     *
     * @param  list<string>  $savedFields  etiquetas de los campos guardados en esta pantalla
     */
    public function recordProgress(WhatsAppFlowSession $session, string $screenId, array $savedFields): void
    {
        $progress = $session->progress ?? [];
        $path = $progress['path'] ?? $this->inferredPath($session, $screenId);
        $position = array_search($screenId, $path, true);
        $path = $position === false ? $path : array_slice($path, 0, $position);
        $path[] = $screenId;

        $progress = [
            'path' => $path,
            'saved_fields' => array_values(array_unique([...($progress['saved_fields'] ?? []), ...$savedFields])),
            'updated_at' => now()->toIso8601String(),
        ];
        $session->update(['progress' => $progress]);

        $index = FlowDefinitionCompiler::screenIndex($screenId);
        $definition = $session->flow->liveVersion()->definition;
        $this->updateMessage($session, ['progress' => [
            'screens_done' => count($path),
            'last_screen_title' => $index !== null ? ($definition['screens'][$index]['title'] ?? null) : null,
            'saved_fields' => $progress['saved_fields'],
            'updated_at' => $progress['updated_at'],
        ]]);
    }

    /**
     * Respuestas de las pantallas del recorrido. Las de un camino abandonado
     * (el cliente volvió atrás y eligió otra opción) no se incluyen. Una
     * sesión sin recorrido registrado devuelve todo lo guardado.
     */
    public function pathAnswers(WhatsAppFlowSession $session): array
    {
        $screens = $session->flow->liveVersion()->definition['screens'] ?? null;

        if ($screens === null || $session->progress === null) {
            return $session->data ?? [];
        }

        $keys = [];
        foreach ($session->progress['path'] ?? [] as $screenId) {
            foreach ($screens[FlowDefinitionCompiler::screenIndex($screenId) ?? -1]['blocks'] ?? [] as $block) {
                if (isset($block['key'])) {
                    $keys[] = $block['key'];
                }
            }
        }

        return Arr::only($session->data ?? [], $keys);
    }

    /**
     * Recorrido de una sesión que guardó respuestas antes de que se
     * registrara el recorrido: las pantallas anteriores con alguna respuesta.
     *
     * @return list<string>
     */
    private function inferredPath(WhatsAppFlowSession $session, string $screenId): array
    {
        $screens = $session->flow->liveVersion()->definition['screens'] ?? [];
        $data = $session->data ?? [];
        $path = [];

        foreach (array_slice($screens, 0, FlowDefinitionCompiler::screenIndex($screenId) ?? 0) as $index => $screen) {
            if (Arr::hasAny($data, array_column($screen['blocks'] ?? [], 'key'))) {
                $path[] = FlowDefinitionCompiler::screenId($index);
            }
        }

        return $path;
    }

    /**
     * Actualiza la tarjeta del formulario en el chat (sólo mensajes
     * interactivos de formulario). La fila se bloquea porque el avance y el
     * reemplazo de un envío pueden escribir a la vez sobre `interactive`.
     */
    public function updateMessage(WhatsAppFlowSession $session, array $changes): void
    {
        $messageId = $session->message_id;

        if ($messageId === null) {
            return;
        }

        $updated = DB::transaction(function () use ($messageId, $changes): bool {
            $message = Message::withoutGlobalScopes()->whereKey($messageId)->lockForUpdate()->first();

            if ($message === null || $message->message_type !== MessageType::Flow) {
                return false;
            }

            $message->update(['interactive' => [...($message->interactive ?? []), ...$changes]]);

            return true;
        });

        if ($updated) {
            // Por cola: el endpoint llama acá en cada pantalla y Meta corta a
            // los 10 s, así que un broadcaster lento no puede demorarlo.
            dispatch(function () use ($messageId): void {
                $message = Message::withoutGlobalScopes()->find($messageId);

                if ($message === null) {
                    return;
                }

                try {
                    event(new MessageEdited($message));
                } catch (\Throwable $e) {
                    Log::warning('No se pudo emitir el avance del formulario', ['message_id' => $messageId, 'error' => $e->getMessage()]);
                }
            });
        }
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
        $blocks = $index !== null ? $flow->liveVersion()->screenBlocks($index) : null;

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

    /** Envíos sin terminar y todavía vigentes del formulario al contacto. */
    private function unfinished(WhatsAppFlow $flow, ?int $contactId): Builder
    {
        return WhatsAppFlowSession::withoutGlobalScopes()
            ->where('whatsapp_flow_id', $flow->id)
            ->where('contact_id', $contactId ?? 0)
            ->where('status', WhatsAppFlowSession::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }

    /**
     * Si el template tiene un botón FLOW, abre una sesión y agrega el
     * componente del botón con su flow_token. Sin formulario local, Meta usa
     * el token por defecto (`unused`) y la respuesta se asocia por context.id.
     * Con `navigate`, los datos de la primera pantalla viajan en
     * `flow_action_data`; con `data_exchange` los pide el formulario al endpoint.
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
        $prefill = $button['flow_action'] === 'navigate' ? $this->templatePrefill($session, $button['navigate_screen']) : [];
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

    /** Datos de la pantalla de entrada; vacíos si no se conoce qué declara. */
    private function templatePrefill(WhatsAppFlowSession $session, ?string $screenId): array
    {
        $version = $session->flow->liveVersion();
        $screenId ??= $version->entryScreen();

        if ($screenId === null || $version->declaredDataKeys($screenId) === null) {
            return [];
        }

        return $this->screenData($session, $screenId);
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
        $declared = $flow->liveVersion()->declaredDataKeys($screenId);

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
