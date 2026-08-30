<?php

namespace App\Services;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Enums\TemplateStatus;
use App\Events\MessageSent;
use App\Events\TenantMessageReceived;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Scopes\TenantScope;
use App\Models\WhatsAppConfig;
use App\Models\WhatsAppTemplate;
use App\Services\Concerns\ResolvesWhatsAppCredentials;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppTemplateService
{
    use ResolvesWhatsAppCredentials;

    private function graphVersion(): string
    {
        return (string) config('services.facebook.graph_version', 'v26.0');
    }

    /**
     * Sincronizar templates desde Meta Graph API.
     *
     * @return int Cantidad de templates sincronizados
     */
    public function syncTemplates(WhatsAppConfig $config, int $tenantId): int
    {
        $token = $config->getDecryptedToken();
        if (! $token) {
            throw new \RuntimeException('No se pudo desencriptar el token de WhatsApp');
        }

        $wabaId = $config->waba_id;

        $nextUrl = 'https://graph.facebook.com/'.$this->graphVersion()."/{$wabaId}/message_templates";
        $query = ['fields' => 'id,name,language,status,category,components,rejected_reason', 'limit' => 250];
        $syncedExternalIds = [];
        $count = 0;

        do {
            $response = Http::withToken($token)->timeout(15)->get($nextUrl, $query);
            if (! $response->successful()) {
                Log::error('Error sincronizando templates de WhatsApp', [
                    'waba_id' => $wabaId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('Error sincronizando templates: '.$response->body());
            }

            foreach ($response->json('data', []) as $templateData) {
                $externalId = $templateData['id'];
                $syncedExternalIds[] = $externalId;

                WhatsAppTemplate::withoutGlobalScope(TenantScope::class)->updateOrCreate(
                    [
                        'whatsapp_config_id' => $config->id,
                        'name' => $templateData['name'],
                        'language' => $templateData['language'],
                    ],
                    [
                        'tenant_id' => $tenantId,
                        'external_id' => $externalId,
                        'category' => $templateData['category'],
                        'status' => TemplateStatus::tryFrom((string) ($templateData['status'] ?? '')) ?? TemplateStatus::Unknown,
                        'rejected_reason' => $templateData['rejected_reason'] ?? null,
                        'components' => $templateData['components'] ?? [],
                        'synced_at' => now(),
                    ]
                );
                $count++;
            }

            $nextUrl = $response->json('paging.next');
            $query = [];
        } while ($nextUrl);

        // Marcar como DISABLED los templates que ya no existen en Meta
        if (! empty($syncedExternalIds)) {
            WhatsAppTemplate::withoutGlobalScope(TenantScope::class)
                ->where('whatsapp_config_id', $config->id)
                ->whereNotIn('external_id', $syncedExternalIds)
                ->where('status', '!=', TemplateStatus::Disabled)
                ->update(['status' => TemplateStatus::Disabled]);
        }

        return $count;
    }

    /**
     * Subir un archivo a Meta (/media) para usarlo como header de plantilla.
     * Devuelve el media id (válido ~30 días en Meta).
     */
    public function uploadMedia(WhatsAppConfig $config, UploadedFile $file): string
    {
        $token = $config->getDecryptedToken();
        if (! $token) {
            throw new \RuntimeException('No se pudo desencriptar el token de WhatsApp');
        }

        $response = Http::withToken($token)
            ->timeout(60)
            ->attach('file', $file->get(), $file->getClientOriginalName(), ['Content-Type' => $file->getMimeType()])
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$config->phone_number_id}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $file->getMimeType(),
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Error subiendo archivo a WhatsApp: '.$response->body());
        }

        return (string) $response->json('id');
    }

    /**
     * Upload the sample asset used during Meta template review.
     * This is intentionally separate from /media, which is used to send messages.
     */
    public function uploadTemplateHeaderHandle(WhatsAppConfig $config, UploadedFile $file): string
    {
        $token = $config->getDecryptedToken();
        $appId = config('services.facebook.app_id');
        if (! $token || ! $appId) {
            throw new \RuntimeException('Faltan credenciales de Meta para cargar el archivo de ejemplo.');
        }

        $contents = $file->get();
        $session = Http::withToken($token)
            ->timeout(60)
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$appId}/uploads", [
                'file_length' => strlen($contents),
                'file_type' => $file->getMimeType(),
                'file_name' => $file->getClientOriginalName(),
            ]);

        if (! $session->successful() || ! $session->json('id')) {
            throw new \RuntimeException('Error iniciando la carga del archivo en Meta: '.$session->body());
        }

        $upload = Http::withToken($token)
            ->withHeaders(['file_offset' => '0'])
            ->withBody($contents, $file->getMimeType() ?: 'application/octet-stream')
            ->timeout(120)
            ->post('https://graph.facebook.com/'.$this->graphVersion().'/'.$session->json('id'));

        if (! $upload->successful() || ! $upload->json('h')) {
            throw new \RuntimeException('Error subiendo el archivo de ejemplo a Meta: '.$upload->body());
        }

        return (string) $upload->json('h');
    }

    /**
     * Create a template in the selected WABA and mirror the initial review state locally.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createTemplate(WhatsAppConfig $config, int $tenantId, array $payload): WhatsAppTemplate
    {
        $token = $config->getDecryptedToken();
        if (! $token) {
            throw new \RuntimeException('No se pudo desencriptar el token de WhatsApp');
        }

        $response = Http::withToken($token)
            ->timeout(30)
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$config->waba_id}/message_templates", [
                'name' => $payload['name'],
                'language' => $payload['language'],
                'category' => $payload['category'],
                'parameter_format' => 'named',
                'components' => $payload['components'],
            ]);

        if (! $response->successful() || ! $response->json('id')) {
            Log::warning('Error creando template de WhatsApp', [
                'waba_id' => $config->waba_id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Meta rechazó la creación de la plantilla: '.$response->json('error.message', $response->body()));
        }

        return WhatsAppTemplate::withoutGlobalScope(TenantScope::class)->updateOrCreate(
            [
                'whatsapp_config_id' => $config->id,
                'name' => $payload['name'],
                'language' => $payload['language'],
            ],
            [
                'tenant_id' => $tenantId,
                'external_id' => (string) $response->json('id'),
                'category' => $response->json('category', $payload['category']),
                'status' => TemplateStatus::tryFrom((string) $response->json('status', 'PENDING')) ?? TemplateStatus::Pending,
                'components' => $payload['components'],
                'rejected_reason' => null,
                'synced_at' => now(),
            ],
        );
    }

    /**
     * Permanently delete one template translation from Meta and its local mirror.
     */
    public function deleteTemplate(WhatsAppConfig $config, WhatsAppTemplate $template): void
    {
        $token = $config->getDecryptedToken();
        if (! $token) {
            throw new \RuntimeException('No se pudo desencriptar el token de WhatsApp');
        }

        $response = Http::withToken($token)
            ->timeout(30)
            ->withQueryParameters([
                'hsm_id' => $template->external_id,
                'name' => $template->name,
            ])
            ->delete('https://graph.facebook.com/'.$this->graphVersion()."/{$config->waba_id}/message_templates");

        if (($response->successful() && $response->json('success') === true) || $this->templateNoLongerExists($response)) {
            $template->delete();

            return;
        }

        Log::warning('Error eliminando template de WhatsApp', [
            'waba_id' => $config->waba_id,
            'template_id' => $template->external_id,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $message = $response->json('error.error_data.details')
            ?? $response->json('error.message')
            ?? $response->body();

        throw new \RuntimeException('Meta no pudo eliminar la plantilla: '.$message);
    }

    /**
     * Meta's documented subcode for "template name does not exist" on the
     * DELETE /message_templates endpoint. A bare 404 or a message-text match
     * is not reliable enough to justify deleting the local mirror.
     */
    private const TEMPLATE_NOT_FOUND_SUBCODE = 2388023;

    private function templateNoLongerExists(Response $response): bool
    {
        return $response->json('error.error_subcode') === self::TEMPLATE_NOT_FOUND_SUBCODE;
    }

    /**
     * Tokens de personalización que se resuelven por conversación al enviar.
     * Permiten que una difusión salga personalizada: el valor del parámetro
     * puede ser "{{nombre}}" y cada destinatario recibe su propio nombre.
     */
    private function resolvePersonalizationTokens(array $components, Conversation $conversation): array
    {
        $replacements = [
            '{{nombre}}' => $conversation->contact?->name ?? '',
            '{{telefono}}' => $conversation->contact?->phone ?? '',
        ];

        foreach ($components as &$component) {
            if (! is_array($component['parameters'] ?? null)) {
                continue;
            }
            foreach ($component['parameters'] as &$param) {
                if (isset($param['text']) && is_string($param['text'])) {
                    $param['text'] = strtr($param['text'], $replacements);
                }
            }
            unset($param);
        }
        unset($component);

        return $components;
    }

    /**
     * Meta acepta el envío (devuelve wamid) de un header DOCUMENT que solo trae
     * `link`, pero NO entrega el mensaje si falta `filename`: el documento se
     * descarta silenciosamente y el fallo solo llegaría por el webhook de
     * estado. Para image/video el `link` alcanza; document exige nombre.
     *
     * El filename es lo que el receptor ve como nombre del documento en el
     * chat. Se usa el nombre del template (no el hash aleatorio del storage,
     * que sería ilegible) preservando la extensión real de la URL; si la URL
     * no tiene extensión se asume .pdf, el único formato de documento que la
     * app permite subir.
     */
    private function ensureDocumentFilename(array $components, WhatsAppTemplate $template): array
    {
        foreach ($components as &$component) {
            if (strtolower($component['type'] ?? '') !== 'header') {
                continue;
            }
            foreach ($component['parameters'] ?? [] as &$param) {
                if (($param['type'] ?? null) !== 'document' || ! isset($param['document']['link'])) {
                    continue;
                }
                if (! empty($param['document']['filename'])) {
                    continue;
                }
                $param['document']['filename'] = $this->documentFilename($param['document']['link'], $template);
            }
            unset($param);
        }
        unset($component);

        return $components;
    }

    private function documentFilename(string $url, WhatsAppTemplate $template): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION)) ?: 'pdf';

        return $template->name.'.'.$extension;
    }

    /**
     * Enviar un mensaje de template de WhatsApp.
     */
    public function sendTemplateMessage(
        Conversation $conversation,
        WhatsAppTemplate $template,
        array $components,
        ?User $sender,
    ): Message {
        $components = $this->resolvePersonalizationTokens($components, $conversation);
        $components = $this->ensureDocumentFilename($components, $template);

        $channel = $conversation->channel;
        if (! $channel) {
            throw new \InvalidArgumentException('La conversación no tiene un canal asociado.');
        }

        ['business_phone_id' => $businessPhoneId, 'business_token' => $businessToken] =
            $this->resolveWhatsAppCredentials($channel);

        $to = $conversation->contact?->phone;
        if (! $to) {
            throw new \InvalidArgumentException('La conversación no tiene un teléfono de contacto válido.');
        }

        // Normalización de teléfono argentino (mismo patrón que sendTextMessageFromCRM)
        if (strpos($to, '549') === 0) {
            $to = '54'.substr($to, 3);
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template->name,
                'language' => [
                    'code' => $template->language,
                ],
                'components' => $components,
            ],
        ];

        $response = Http::withToken($businessToken)
            ->timeout(10)
            ->post('https://graph.facebook.com/'.$this->graphVersion()."/{$businessPhoneId}/messages", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException("Error enviando template de WhatsApp [{$response->status()}]: ".$response->body());
        }

        // Generar resumen legible del template para el historial de chat
        $contentSummary = $this->buildContentSummary($template, $components);

        $message = Message::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'sender_type' => $sender ? SenderType::USER : SenderType::SYSTEM,
            'sender_id' => $sender?->id,
            'content' => $contentSummary,
            'message_type' => MessageType::Text,
            'direction' => MessageDirection::OUTBOUND,
            // Sin el wamid los webhooks de estado (sent/delivered/failed) no
            // matchean este mensaje y los fallos quedan invisibles.
            'external_id' => $response->json('messages.0.id'),
        ]);

        $conversation->update([
            'last_message_at' => $message->created_at,
            'last_message_content' => $contentSummary,
            // Un envío humano hace handoff; una automatización del sistema no
            // debe desactivar el bot de la conversación.
            ...($sender ? ['ai_autoreply_enabled' => false] : []),
        ]);

        try {
            broadcast(new MessageSent($message));
            broadcast(new TenantMessageReceived($message, $conversation->tenant_id));
        } catch (\Exception $e) {
            Log::error('Error broadcasting template message: '.$e->getMessage());
        }

        return $message;
    }

    public function sendSystemTemplateMessage(
        Conversation $conversation,
        WhatsAppTemplate $template,
        array $components,
    ): Message {
        return $this->sendTemplateMessage($conversation, $template, $components, null);
    }

    /**
     * Detecta las plantillas de "Group invite link" ya sincronizadas: no se
     * crean por API (createTemplate() fuerza parameter_format=named, y la
     * Template Library las gestiona por su propio flujo, vía
     * GET /message_template_library con topic=group_invite_link — un
     * endpoint distinto al que usa syncTemplates()).
     *
     * NOTA: no hay confirmación de qué campo persiste el vínculo con
     * "group_invite_link" en la respuesta de GET /{waba_id}/message_templates
     * (que es lo que syncTemplates() guarda en `components`). La heurística
     * acá es buscar el literal "group_id" en cualquier parte del array
     * serializado de components; hay que verificarla contra una plantilla
     * real sincronizada antes de confiar en ella en producción.
     *
     * @return \Illuminate\Support\Collection<int, WhatsAppTemplate>
     */
    public function findGroupInviteTemplates(int $whatsappConfigId): \Illuminate\Support\Collection
    {
        return WhatsAppTemplate::where('whatsapp_config_id', $whatsappConfigId)
            ->where('status', TemplateStatus::Approved)
            ->get()
            ->filter(fn (WhatsAppTemplate $template) => str_contains(
                json_encode($template->components ?? []),
                'group_id'
            ));
    }

    /**
     * Construir resumen legible del contenido del template.
     *
     * Intenta resolver el body text real del template reemplazando los
     * placeholders {{1}}, {{2}}, etc. con los parámetros enviados.
     * Si no hay body, cae al nombre del template como fallback.
     */
    private function buildContentSummary(WhatsAppTemplate $template, array $components): string
    {
        // Extraer parámetros del body desde los components enviados.
        // Soporta posicionales ({{1}}) y nombrados ({{cliente}} vía parameter_name).
        $bodyParams = [];
        $namedParams = [];
        foreach ($components as $component) {
            $type = strtolower($component['type'] ?? '');
            if ($type === 'body') {
                foreach ($component['parameters'] ?? [] as $param) {
                    $value = $param['text'] ?? $param['image']['link'] ?? $param['document']['link'] ?? '...';
                    $bodyParams[] = $value;
                    if (! empty($param['parameter_name'])) {
                        $namedParams[$param['parameter_name']] = $value;
                    }
                }
            }
        }

        // Buscar el body text del template guardado
        $bodyText = null;
        foreach ($template->components ?? [] as $comp) {
            $type = strtoupper($comp['type'] ?? '');
            if ($type === 'BODY' && ! empty($comp['text'])) {
                $bodyText = $comp['text'];
                break;
            }
        }

        // Si tenemos body text, reemplazar placeholders con los parámetros
        if ($bodyText) {
            foreach ($namedParams as $name => $value) {
                $bodyText = str_replace('{{'.$name.'}}', $value, $bodyText);
            }
            foreach ($bodyParams as $i => $value) {
                $bodyText = str_replace('{{'.($i + 1).'}}', $value, $bodyText);
            }

            return "📋 {$template->name}\n{$bodyText}";
        }

        // Fallback: si hay parámetros de body, mostrarlos en segunda línea para UI legible.
        if (! empty($bodyParams)) {
            return "📋 {$template->name}\n".implode(', ', $bodyParams);
        }

        // Último fallback: solo nombre.
        return "📋 {$template->name}";
    }
}
