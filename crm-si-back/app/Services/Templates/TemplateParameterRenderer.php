<?php

namespace App\Services\Templates;

use App\Enums\ContactFieldType;
use App\Models\ContactField;
use App\Models\MediaAsset;
use App\Models\Scopes\TenantScope;
use App\Models\WhatsAppTemplate;
use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * Parámetros configurados de una plantilla (`{component, name, source, value,
 * path, fallback, media_asset_id}`) → componentes del envío a Meta. Lo usan la
 * acción de automatización y la apertura y el cierre de los formularios.
 *
 * Fuentes: `literal` (texto fijo; en un encabezado de media, la URL),
 * `field` (ruta dentro del contexto, p. ej. `contact.name` o `answers.dni`)
 * y `media_asset` (archivo subido al espacio).
 */
class TemplateParameterRenderer
{
    public const SOURCES = ['literal', 'field', 'media_asset'];

    /** Variable nombrada de un texto, como en las plantillas de Meta: `{{nombre}}`. */
    public const VARIABLE = '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/';

    public function __construct(private PublicUrlGuard $urlGuard) {}

    /**
     * Forma de cada parámetro, sin mirar la plantilla.
     *
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, list<string>>
     */
    public function validateParameters(array $parameters, int $tenantId): array
    {
        $errors = [];

        foreach ($parameters as $index => $parameter) {
            $source = $parameter['source'] ?? null;
            if (! in_array($source, self::SOURCES, true)) {
                $errors["parameters.{$index}.source"][] = 'La fuente no es válida.';
            }
            if ($source === 'field' && empty($parameter['path'])) {
                $errors["parameters.{$index}.path"][] = 'La ruta del campo es obligatoria.';
            }
            if ($source === 'media_asset' && ! MediaAsset::where('tenant_id', $tenantId)->whereKey($parameter['media_asset_id'] ?? null)->exists()) {
                $errors["parameters.{$index}.media_asset_id"][] = 'Seleccioná un archivo del espacio.';
            }
        }

        return $errors;
    }

    /**
     * Que los parámetros cubran lo que la plantilla exige.
     *
     * Meta rechaza (error 132000) todo envío cuya cantidad de parámetros de
     * body no coincida exactamente con la del template. Se valida al guardar
     * para que una configuración incompleta no falle en cada envío, gastando
     * cuota, en lugar de avisar al crearla.
     *
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, list<string>>
     */
    public function validateAgainstTemplate(WhatsAppTemplate $template, array $parameters): array
    {
        $errors = [];
        $expected = $template->expectedBodyParameters();
        $provided = array_values(array_filter(
            $parameters,
            fn ($parameter) => strtolower($parameter['component'] ?? 'body') === 'body',
        ));

        if (count($provided) !== count($expected)) {
            $errors['parameters'][] = "El template «{$template->name}» espera ".count($expected).' parámetro(s) de cuerpo y se definieron '.count($provided).'.';
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
                $parameters,
                fn ($parameter) => strtolower($parameter['component'] ?? 'body') === 'header',
            );
            if (count($headerParams) !== 1) {
                $errors['parameters'][] = "El template «{$template->name}» tiene un encabezado con archivo: definí un parámetro de encabezado con la URL del documento.";
            }
        }

        return $errors;
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, list<string>>
     */
    public function validate(WhatsAppTemplate $template, array $parameters, int $tenantId): array
    {
        return array_merge_recursive(
            $this->validateParameters($parameters, $tenantId),
            $this->validateAgainstTemplate($template, $parameters),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $parameters
     * @param  array<string, mixed>  $context  valores para las rutas `field`
     * @return list<array{type: string, parameters: list<array<string, mixed>>}>
     *
     * @throws TemplateParameterException
     */
    public function render(WhatsAppTemplate $template, array $parameters, array $context, int $tenantId): array
    {
        $mediaFormat = $template->headerMediaFormat();
        $grouped = [];
        foreach ($parameters as $parameter) {
            [$value, $appOwned] = $this->resolve($parameter, $context, $tenantId);
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

            $rendered = ['type' => 'text', 'text' => self::asText($value)];
            if (! empty($parameter['name'])) {
                $rendered['parameter_name'] = $parameter['name'];
            }
            $grouped[$component][] = $rendered;
        }

        return array_map(fn (array $parameters, string $type) => ['type' => $type, 'parameters' => $parameters], $grouped, array_keys($grouped));
    }

    /**
     * Reemplaza cada `{{nombre}}` de un texto por el valor de su variable, con
     * las mismas fuentes y respaldo que una plantilla. Lo usa el mensaje que
     * abre un formulario, que se manda tal cual con la ventana abierta.
     *
     * @param  list<array<string, mixed>>  $parameters  variables del cuerpo, por `name`
     * @param  array<string, mixed>  $context  valores para las rutas `field`
     *
     * @throws TemplateParameterException si una variable no está configurada o queda sin valor
     */
    public function renderText(string $text, array $parameters, array $context, int $tenantId): string
    {
        $byName = collect($parameters)->keyBy(fn (array $parameter) => (string) ($parameter['name'] ?? ''));

        return (string) preg_replace_callback(self::VARIABLE, function (array $match) use ($byName, $context, $tenantId) {
            $parameter = $byName->get($match[1]) ?? throw new TemplateParameterException('missing_parameter:'.$match[1]);

            return self::asText($this->resolve($parameter, $context, $tenantId)[0]);
        }, $text);
    }

    /**
     * Valor de un parámetro y si es un archivo propio de la app (público por
     * construcción); vacío usa el respaldo.
     *
     * @param  array<string, mixed>  $parameter
     * @return array{mixed, bool}
     *
     * @throws TemplateParameterException si queda sin valor
     */
    private function resolve(array $parameter, array $context, int $tenantId): array
    {
        [$value, $appOwned] = match ($parameter['source'] ?? null) {
            'literal' => [$parameter['value'] ?? null, false],
            'media_asset' => [$this->mediaAssetUrl($parameter['media_asset_id'] ?? null, $tenantId), true],
            default => $this->resolveFieldValue($context, (string) ($parameter['path'] ?? ''), $tenantId),
        };
        if ($value === null || $value === '') {
            $value = $parameter['fallback'] ?? null;
            $appOwned = false;
        }
        if ($value === null || $value === '') {
            throw new TemplateParameterException('missing_parameter:'.($parameter['name'] ?? $parameter['path'] ?? 'unknown'));
        }

        return [$value, $appOwned];
    }

    private static function asText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /** URL pública de un archivo del espacio; también la usa la imagen del cierre de un formulario. */
    public function mediaAssetUrl(mixed $mediaAssetId, int $tenantId): ?string
    {
        if (! $mediaAssetId) {
            return null;
        }

        // Sin el scope del tenant (corre en jobs) pero sí el de borrados: borrar un archivo también lo saca del disco.
        $asset = MediaAsset::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->find($mediaAssetId);
        if (! $asset) {
            throw new TemplateParameterException('media_asset_not_found');
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

        if ($field?->type === ContactFieldType::Date) {
            return [$this->formatDate($raw), false];
        }

        return [$raw, false];
    }

    /**
     * Un campo Date guarda la fecha pelada en ISO (`2026-09-13`), que es lo que
     * le llegaba al destinatario en el mensaje. Se muestra como `13/09/2026`,
     * el formato en que se carga y se lee en el resto de la app.
     *
     * Se parsea con formato explícito y sin hora: la fecha no tiene zona, así
     * que convertirla pasando por un timestamp la correría un día para
     * cualquier tenant al oeste de Greenwich. Si el valor guardado no es una
     * fecha ISO (dato viejo, import flojo), se devuelve tal cual en vez de
     * romper el envío.
     */
    private function formatDate(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return $date ? $date->format('d/m/Y') : $raw;
    }

    /**
     * Confirma que Meta va a poder descargar la URL: un HEAD debe responder
     * 2xx sin autenticación. Si falla, se salta el envío con motivo auditable
     * en vez de dejar que Meta lo rechace con un error críptico.
     *
     * La URL puede salir de un campo del contacto (entrada de usuario vía
     * import, edición o webhook), así que antes de pegarle se blinda contra
     * SSRF: solo http/https, el host debe resolver a una IP pública (se rechaza
     * loopback, privada RFC1918, link-local/metadata del cloud, etc.) y se
     * desactivan los redirects para que un 3xx no lleve la request a un host
     * interno saltándose esta validación.
     */
    public function assertPubliclyFetchable(string $url): void
    {
        try {
            [$host, $port, $ips] = $this->urlGuard->assertPublicUrl($url);
        } catch (PublicUrlRejectedException) {
            throw new TemplateParameterException('header_url_not_accessible');
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
            throw new TemplateParameterException('header_url_not_accessible');
        }

        if ($response->redirect() || ! $response->successful()) {
            throw new TemplateParameterException('header_url_not_accessible');
        }
    }
}
