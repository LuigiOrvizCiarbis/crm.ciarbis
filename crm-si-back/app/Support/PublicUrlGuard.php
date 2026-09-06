<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Guard anti-SSRF reutilizable: valida que una URL controlada por el usuario
 * (store_url de WooCommerce, link de un mensaje, URL de un campo de contacto)
 * sea pública antes de contactarla, y sigue pinneando la conexión a las IPs
 * ya validadas para cerrar la ventana de DNS rebinding entre la validación y
 * el request real.
 *
 * Reemplaza la lógica duplicada de WooCommerceService::assertPublicUrl y
 * WhatsAppTemplateActionHandler::assertPublicHost, y suma lo que ninguna de
 * las dos cubría: revalidar cada hop de redirect (un servidor público puede
 * responder con un 3xx hacia 169.254.169.254) y acotar la descarga del body.
 */
class PublicUrlGuard
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    private const ALLOWED_PORTS = [80, 443];

    private const MAX_REDIRECTS = 3;

    private const MAX_BODY_BYTES = 512 * 1024;

    private const TIMEOUT_SECONDS = 5;

    /**
     * Valida que la URL sea pública y devuelve [host, port, ips] validados,
     * listos para pinnear con CURLOPT_RESOLVE.
     *
     * @return array{0: string, 1: int, 2: list<string>}
     *
     * @throws PublicUrlRejectedException
     */
    public function assertPublicUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new PublicUrlRejectedException('URL inválida.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new PublicUrlRejectedException('La URL debe usar http o https.');
        }

        if (isset($parts['port']) && ! in_array($parts['port'], self::ALLOWED_PORTS, true)) {
            throw new PublicUrlRejectedException('Puerto no permitido.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return [$host, $port, [$host]];
        }

        $ips = $this->resolveHost($host);
        if ($ips === []) {
            throw new PublicUrlRejectedException('No se pudo resolver el dominio.');
        }

        foreach ($ips as $ip) {
            $this->assertPublicIp($ip);
        }

        return [$host, $port, $ips];
    }

    /**
     * Hace un GET a una URL pública, revalidando cada hop de redirect contra
     * este mismo guard y pinneando la conexión a las IPs validadas. Corta la
     * descarga del body a $maxBytes (default MAX_BODY_BYTES) y no sigue más
     * de MAX_REDIRECTS.
     *
     * Cuando $requireContentType viene seteado, se exige que la respuesta
     * final declare un Content-Type con ese prefijo (p.ej. "text/html",
     * "image/") antes de aceptar el body.
     *
     * @throws PublicUrlRejectedException
     */
    public function fetch(string $url, ?string $requireContentType = null, ?int $maxBytes = null): PublicUrlFetchResult
    {
        $currentUrl = $url;
        $limit = $maxBytes ?? self::MAX_BODY_BYTES;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $port, $ips] = $this->assertPublicUrl($currentUrl);

            // CURLOPT_WRITEFUNCTION corta la transferencia en la capa de
            // socket apenas se supera el límite: sin esto, ->body() ya
            // materializó la respuesta completa en memoria antes de que
            // truncateBody() pudiera recortarla, y un servidor que decide
            // mandar varios GB tumba al worker pese al límite "advertido".
            //
            // Http::fake() (tests) nunca invoca este callback porque no pasa
            // por cURL real: $state->invoked distingue ese caso para no
            // descartar el body del fake, que sí viene completo y acotado
            // por el propio test.
            $state = new SizeLimitedWriterState;
            $writeFunction = self::sizeLimitedWriter($state, $limit);

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => ["{$host}:{$port}:".implode(',', $ips)],
                        CURLOPT_WRITEFUNCTION => $writeFunction,
                    ],
                ])
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->get($currentUrl);
            } catch (ConnectionException $exception) {
                if ($state->exceeded) {
                    throw new PublicUrlRejectedException('La respuesta superó el tamaño permitido.', previous: $exception);
                }

                throw new PublicUrlRejectedException('No se pudo conectar con la URL.', previous: $exception);
            }

            if ($response->redirect()) {
                $location = $response->header('Location');
                if (! $location) {
                    throw new PublicUrlRejectedException('Redirect sin Location.');
                }

                $currentUrl = $this->resolveRedirectTarget($currentUrl, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new PublicUrlRejectedException("La URL respondió con estado {$response->status()}.");
            }

            $contentType = (string) $response->header('Content-Type');
            if ($requireContentType !== null && ! str_starts_with(strtolower($contentType), strtolower($requireContentType))) {
                throw new PublicUrlRejectedException("Content-Type inesperado: {$contentType}.");
            }

            $body = $state->invoked ? $state->received : $this->truncateBody($response, $limit);

            return new PublicUrlFetchResult(
                finalUrl: $currentUrl,
                status: $response->status(),
                contentType: $contentType,
                body: $body,
            );
        }

        throw new PublicUrlRejectedException('Demasiados redirects.');
    }

    /**
     * Fallback para cuando CURLOPT_WRITEFUNCTION no corrió (Http::fake() en
     * tests, que no pasa por cURL real): el body del fake ya vino completo y
     * acotado por el propio test, así que sólo hace falta recortarlo a mano.
     */
    private function truncateBody(Response $response, int $maxBytes): string
    {
        $body = $response->body();

        return strlen($body) > $maxBytes
            ? substr($body, 0, $maxBytes)
            : $body;
    }

    /**
     * Construye el CURLOPT_WRITEFUNCTION que acumula en $state->received y
     * corta la transferencia (devolviendo menos bytes de los recibidos, que
     * cURL interpreta como CURLE_WRITE_ERROR) apenas se supera $limit.
     * Extraído a un método propio, sin capturar $this, para poder testear la
     * lógica de corte de forma aislada sin pasar por cURL/red real.
     */
    public static function sizeLimitedWriter(SizeLimitedWriterState $state, int $limit): \Closure
    {
        return function ($ch, string $chunk) use ($state, $limit): int {
            $state->invoked = true;
            $state->received .= $chunk;
            if (strlen($state->received) > $limit) {
                $state->exceeded = true;

                return 0;
            }

            return strlen($chunk);
        };
    }

    private function resolveRedirectTarget(string $currentUrl, string $location): string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        // Location relativa: resolver contra la URL actual.
        $base = parse_url($currentUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }

        $path = $base['path'] ?? '/';
        $dir = rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/') ?: '';

        return "{$scheme}://{$host}{$port}{$dir}/{$location}";
    }

    /**
     * Resuelve un hostname a todas sus IPs A (IPv4) y AAAA (IPv6).
     * gethostbynamel() solo devuelve IPv4, así que un target IPv6 quedaba sin
     * validar si no se consulta también AAAA.
     *
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        $ips = [];

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if ($ip !== null) {
                    $ips[] = $ip;
                }
            }
        }

        if ($ips === []) {
            $ipv4 = gethostbynamel($host);
            if (is_array($ipv4)) {
                $ips = $ipv4;
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Rechaza IPs no enrutables públicamente (loopback, privadas RFC1918,
     * link-local 169.254/16 —incluye el metadata endpoint de los clouds—,
     * etc.).
     *
     * @throws PublicUrlRejectedException
     */
    private function assertPublicIp(string $ip): void
    {
        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isPublic === false) {
            throw new PublicUrlRejectedException('La URL apunta a una dirección no permitida.');
        }
    }
}
