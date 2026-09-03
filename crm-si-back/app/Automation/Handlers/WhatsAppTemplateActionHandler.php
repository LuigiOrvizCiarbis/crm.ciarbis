<?php

namespace App\Automation\Handlers;

use App\Automation\AutomationContext;
use App\Automation\Contracts\ActionHandler;
use App\Automation\Exceptions\ActionSkippedException;
use App\Automation\Exceptions\AmbiguousDeliveryException;
use App\Automation\Exceptions\RetryableActionException;
use App\Enums\ChannelType;
use App\Enums\ContactFieldType;
use App\Models\AutomationAction;
use App\Models\AutomationRun;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Conversation;
use App\Models\MediaAsset;
use App\Models\PipelineStage;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppTemplateService;
use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppTemplateActionHandler implements ActionHandler
{
    public function __construct(
        private AutomationContext $context,
        private WhatsAppTemplateService $templates,
        private PublicUrlGuard $urlGuard,
    ) {}

    public function type(): string
    {
        return 'whatsapp_template';
    }

    public function metadata(): array
    {
        return [
            'label' => 'Enviar template de WhatsApp',
            'config_fields' => ['channel_id', 'template_id', 'parameters'],
            'parameter_sources' => ['literal', 'field'],
        ];
    }

    public function validate(array $config, int $tenantId): array
    {
        $errors = [];
        $channel = Channel::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($config['channel_id'] ?? null);
        if (! $channel || $channel->type !== ChannelType::WHATSAPP) {
            $errors['channel_id'][] = 'Seleccioná un canal de WhatsApp del espacio.';
        }

        $template = WhatsAppTemplate::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($config['template_id'] ?? null);
        if (! $template) {
            $errors['template_id'][] = 'Seleccioná un template del espacio.';
        }
        if ($channel && $template && $channel->whatsapp_config_id !== $template->whatsapp_config_id) {
            $errors['template_id'][] = 'El template no pertenece a la cuenta del canal.';
        }

        foreach ($config['parameters'] ?? [] as $index => $parameter) {
            $source = $parameter['source'] ?? null;
            if (! in_array($source, ['literal', 'field', 'media_asset'], true)) {
                $errors["parameters.{$index}.source"][] = 'La fuente no es válida.';
            }
            if ($source === 'field' && empty($parameter['path'])) {
                $errors["parameters.{$index}.path"][] = 'La ruta del campo es obligatoria.';
            }
            if ($source === 'media_asset' && ! MediaAsset::where('tenant_id', $tenantId)->whereKey($parameter['media_asset_id'] ?? null)->exists()) {
                $errors["parameters.{$index}.media_asset_id"][] = 'Seleccioná un archivo del espacio.';
            }
        }

        // Meta rechaza (error 132000) todo envío cuya cantidad de parámetros de
        // body no coincida exactamente con la del template. Se valida acá para
        // que una regla incompleta no se guarde y falle en cada disparo,
        // gastando cuota, en lugar de avisar al crearla.
        if ($template) {
            $expected = $template->expectedBodyParameters();
            $provided = array_values(array_filter(
                $config['parameters'] ?? [],
                fn ($parameter) => strtolower($parameter['component'] ?? 'body') === 'body',
            ));

            if (count($provided) !== count($expected)) {
                $errors['parameters'][] = "El template «{$template->name}» espera ".count($expected).' parámetro(s) de cuerpo y la acción define '.count($provided).'.';
            }

            $providedNames = array_filter(array_map(fn ($parameter) => $parameter['name'] ?? null, $provided));
            $missing = array_diff($expected, $providedNames);
            if ($missing !== [] && $providedNames !== []) {
                $errors['parameters'][] = 'Faltan parámetros del template: '.implode(', ', $missing).'.';
            }

            // Un header de media necesita exactamente un parámetro con la URL del
            // archivo; sin él Meta rechaza el envío por header faltante.
            if ($template->headerMediaFormat() !== null) {
                $headerParams = array_filter(
                    $config['parameters'] ?? [],
                    fn ($parameter) => strtolower($parameter['component'] ?? 'body') === 'header',
                );
                if (count($headerParams) !== 1) {
                    $errors['parameters'][] = "El template «{$template->name}» tiene un encabezado con archivo: definí un parámetro de encabezado con la URL del documento.";
                }
            }
        }

        return $errors;
    }

    public function preview(AutomationAction $action, AutomationRun $run): array
    {
        [$channel, $template] = $this->resolveConfiguration($action, $run);
        $contact = $this->contact($run);
        $conversation = Conversation::where('contact_id', $contact->id)->where('channel_id', $channel->id)->first();

        return [
            'channel' => ['id' => $channel->id, 'name' => $channel->name, 'status' => $channel->status],
            'template' => ['id' => $template->id, 'name' => $template->name, 'status' => $template->status->value],
            'conversation' => $conversation ? ['id' => $conversation->id, 'will_create' => false] : ['id' => null, 'will_create' => true],
            'parameters' => $this->renderParameters($action, $run, $template),
        ];
    }

    public function execute(AutomationAction $action, AutomationRun $run): array
    {
        [$channel, $template] = $this->resolveConfiguration($action, $run);
        $contact = $this->contact($run);
        if (! $contact->phone) {
            throw new ActionSkippedException('contact_phone_missing');
        }
        if (! $channel->isActive()) {
            throw new ActionSkippedException('channel_inactive');
        }
        if (! $template->isApproved()) {
            throw new ActionSkippedException('template_not_approved');
        }
        if ($channel->branch_id && $contact->branch_id && $channel->branch_id !== $contact->branch_id) {
            throw new ActionSkippedException('incompatible_branch');
        }

        $conversation = Conversation::firstOrCreate(
            ['tenant_id' => $run->tenant_id, 'contact_id' => $contact->id, 'channel_id' => $channel->id],
            [
                'status' => 'open',
                'branch_id' => $contact->branch_id ?? $channel->branch_id,
                'pipeline_stage_id' => PipelineStage::where('tenant_id', $run->tenant_id)->orderByDesc('is_default')->orderBy('sort_order')->value('id'),
                'ai_autoreply_enabled' => (bool) $channel->whatsappConfig?->ai_autoreply_default,
            ],
        );

        $components = $this->renderParameters($action, $run, $template);
        $rateKey = "automation-whatsapp:{$channel->whatsapp_config_id}";
        if (! RateLimiter::attempt($rateKey, 80, fn () => true, 60)) {
            throw new RetryableActionException('whatsapp_rate_limit');
        }

        try {
            $message = $this->templates->sendSystemTemplateMessage($conversation, $template, $components);
        } catch (ConnectionException $exception) {
            throw new AmbiguousDeliveryException('meta_timeout_after_send', previous: $exception);
        } catch (\RuntimeException $exception) {
            if (str_contains($exception->getMessage(), '429') || preg_match('/\b5\d\d\b/', $exception->getMessage())) {
                throw new RetryableActionException($exception->getMessage(), previous: $exception);
            }
            throw $exception;
        }

        return ['message_id' => $message->id, 'external_id' => $message->external_id, 'conversation_id' => $conversation->id];
    }

    private function resolveConfiguration(AutomationAction $action, AutomationRun $run): array
    {
        $channel = Channel::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->with('whatsappConfig')->find($action->config['channel_id'] ?? null);
        $template = WhatsAppTemplate::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->find($action->config['template_id'] ?? null);
        if (! $channel || $channel->type !== ChannelType::WHATSAPP) {
            throw new ActionSkippedException('channel_not_found');
        }
        if (! $template || $template->whatsapp_config_id !== $channel->whatsapp_config_id) {
            throw new ActionSkippedException('template_not_found');
        }

        return [$channel, $template];
    }

    private function contact(AutomationRun $run)
    {
        $context = $this->context->forRun($run);
        $contactId = Arr::get($context, 'contact.id');
        $contact = Contact::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->find($contactId);
        if (! $contact) {
            throw new ActionSkippedException('contact_not_found');
        }

        return $contact;
    }

    private function mediaAssetUrl(mixed $mediaAssetId, int $tenantId): ?string
    {
        if (! $mediaAssetId) {
            return null;
        }

        $asset = MediaAsset::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($mediaAssetId);
        if (! $asset) {
            throw new ActionSkippedException('media_asset_not_found');
        }

        return $asset->publicUrl();
    }

    /**
     * Resuelve el valor de un parámetro con fuente "campo". Devuelve
     * [valor, esArchivoPropio]: para la mayoría de los campos es el valor
     * guardado tal cual (no propio), pero un campo custom de tipo File guarda un
     * media_asset_id que se traduce a la URL pública del archivo — propio, y por
     * eso público garantizado. Así el usuario elige el campo y el sistema
     * entrega la URL correcta según el tipo, sin que la alerta lo sepa.
     *
     * @return array{mixed, bool}
     */
    private function resolveFieldValue(array $context, string $path, int $tenantId): array
    {
        $raw = Arr::get($context, $path);

        if ($raw === null || ! str_starts_with($path, 'contact.custom_data.')) {
            return [$raw, false];
        }

        $key = substr($path, strlen('contact.custom_data.'));
        $field = ContactField::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->first();

        if ($field?->type === ContactFieldType::File) {
            return [$this->mediaAssetUrl($raw, $tenantId), true];
        }

        return [$raw, false];
    }

    /**
     * Confirma que Meta va a poder descargar la URL: un HEAD debe responder
     * 2xx sin autenticación. Si falla, se salta la acción con motivo auditable
     * en vez de dejar que Meta la rechace con un error críptico en cada disparo.
     *
     * La URL sale de un campo del contacto (entrada de usuario vía import,
     * edición o webhook), así que antes de pegarle se blinda contra SSRF:
     * solo http/https, el host debe resolver a una IP pública (se rechaza
     * loopback, privada RFC1918, link-local/metadata del cloud, etc.) y se
     * desactivan los redirects para que un 3xx no lleve la request a un host
     * interno saltándose esta validación.
     */
    private function assertPubliclyFetchable(string $url): void
    {
        try {
            [$host, $port, $ips] = $this->urlGuard->assertPublicUrl($url);
        } catch (PublicUrlRejectedException) {
            throw new ActionSkippedException('header_url_not_accessible');
        }

        try {
            // Pinnear la conexión a las IPs ya validadas (CURLOPT_RESOLVE) evita que
            // una segunda resolución DNS devuelva una IP interna entre la validación
            // y el request (DNS rebinding / TOCTOU). El Host original se preserva
            // para TLS/SNI y vhosts.
            $response = Http::withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:".implode(',', $ips)]],
            ])->timeout(8)->head($url);
        } catch (ConnectionException) {
            throw new ActionSkippedException('header_url_not_accessible');
        }

        if ($response->redirect() || ! $response->successful()) {
            throw new ActionSkippedException('header_url_not_accessible');
        }
    }

    private function renderParameters(AutomationAction $action, AutomationRun $run, WhatsAppTemplate $template): array
    {
        $context = $this->context->forRun($run);
        $mediaFormat = $template->headerMediaFormat();
        $grouped = [];
        foreach ($action->config['parameters'] ?? [] as $parameter) {
            $source = $parameter['source'] ?? null;
            [$value, $appOwned] = match ($source) {
                'literal' => [$parameter['value'] ?? null, false],
                'media_asset' => [$this->mediaAssetUrl($parameter['media_asset_id'] ?? null, $run->tenant_id), true],
                default => $this->resolveFieldValue($context, (string) ($parameter['path'] ?? ''), $run->tenant_id),
            };
            if ($value === null || $value === '') {
                $value = $parameter['fallback'] ?? null;
                $appOwned = false;
            }
            if ($value === null || $value === '') {
                throw new ActionSkippedException('missing_parameter:'.($parameter['name'] ?? $parameter['path'] ?? 'unknown'));
            }

            $component = strtolower($parameter['component'] ?? 'body');

            // El header de media espera la URL del archivo, con la forma
            // {type:"document",document:{link:...}} (o image/video), no un texto.
            if ($component === 'header' && $mediaFormat !== null) {
                // Los archivos propios de la app son públicos por construcción;
                // una URL externa (literal o campo URL) puede no serlo, y Meta la
                // rechazaría. Se verifica antes de enviar y se salta con motivo
                // auditable si no es descargable públicamente.
                if (! $appOwned) {
                    $this->assertPubliclyFetchable((string) $value);
                }
                $kind = strtolower($mediaFormat);
                $grouped['header'][] = ['type' => $kind, $kind => ['link' => (string) $value]];

                continue;
            }

            $rendered = ['type' => 'text', 'text' => (string) $value];
            if (! empty($parameter['name'])) {
                $rendered['parameter_name'] = $parameter['name'];
            }
            $grouped[$component][] = $rendered;
        }

        return array_map(fn (array $parameters, string $type) => ['type' => $type, 'parameters' => $parameters], $grouped, array_keys($grouped));
    }
}
