<?php

namespace App\Services;

use App\Models\LinkPreview;
use App\Support\PublicUrlFetchResult;
use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Genera previews estilo Open Graph de links compartidos en un mensaje: extrae
 * la primera URL del contenido, la descarga vía PublicUrlGuard (anti-SSRF) y
 * parsea title/description/image de sus meta tags. La preview se cachea por
 * url_hash (LinkPreview) y se comparte entre tenants: es contenido público.
 */
class LinkPreviewService
{
    /** Días de vida de una preview 'ok' antes de considerarla vieja y reintentar. */
    public const FRESHNESS_DAYS = 7;

    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    public function __construct(private PublicUrlGuard $urlGuard) {}

    /**
     * Extrae la primera URL de un texto de mensaje, con el mismo criterio
     * "primer link" que ya usa el autolink del front.
     */
    public function extractFirstUrl(?string $content): ?string
    {
        if (! $content) {
            return null;
        }

        return preg_match('#https?://\S+#i', $content, $matches) ? rtrim($matches[0], '.,;:!?)]}\'"') : null;
    }

    public function urlHash(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * Hace (o rehace) el fetch de una preview y persiste el resultado en la
     * fila dada, dejándola en status 'ok' o 'failed'.
     */
    public function fetch(LinkPreview $preview): LinkPreview
    {
        try {
            $result = $this->urlGuard->fetch($preview->url, requireContentType: 'text/html');
            $meta = $this->parseHtml($result->body, $result->finalUrl);

            $imagePath = null;
            if ($meta['image'] !== null) {
                $imagePath = $this->downloadImage($meta['image'], $result->finalUrl, $preview->url_hash);
            }

            $preview->fill([
                'title' => $meta['title'],
                'description' => $meta['description'],
                'site_name' => $meta['site_name'],
                'image_path' => $imagePath,
                'status' => 'ok',
                'fetched_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ])->save();
        } catch (PublicUrlRejectedException $exception) {
            $preview->fill([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => Str::limit($exception->getMessage(), 250),
            ])->save();
        }

        return $preview;
    }

    /**
     * @return array{title: ?string, description: ?string, site_name: ?string, image: ?string}
     */
    private function parseHtml(string $html, string $baseUrl): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Sin la declaración de encoding, loadHTML() asume Latin-1 y corrompe
        // cualquier tilde/ñ del HTML (mismo fix que MailHtmlSanitizer).
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        $title = $this->meta($xpath, 'og:title') ?? $this->meta($xpath, 'twitter:title');
        if ($title === null) {
            $titleNode = $xpath->query('//title')->item(0);
            $title = $titleNode ? trim($titleNode->textContent) : null;
        }

        $description = $this->meta($xpath, 'og:description')
            ?? $this->metaByName($xpath, 'description')
            ?? $this->meta($xpath, 'twitter:description');

        $siteName = $this->meta($xpath, 'og:site_name');

        $image = $this->meta($xpath, 'og:image') ?? $this->meta($xpath, 'twitter:image');
        if ($image !== null) {
            $image = $this->resolveUrl($image, $baseUrl);
        }

        return [
            'title' => $title !== null ? Str::limit($title, 250, '') : null,
            'description' => $description !== null ? Str::limit($description, 1000, '') : null,
            'site_name' => $siteName !== null ? Str::limit($siteName, 250, '') : null,
            'image' => $image,
        ];
    }

    private function meta(DOMXPath $xpath, string $property): ?string
    {
        $node = $xpath->query("//meta[@property='{$property}']/@content")->item(0);
        $value = $node ? trim($node->textContent) : '';

        return $value === '' ? null : $value;
    }

    private function metaByName(DOMXPath $xpath, string $name): ?string
    {
        $node = $xpath->query("//meta[@name='{$name}']/@content")->item(0);
        $value = $node ? trim($node->textContent) : '';

        return $value === '' ? null : $value;
    }

    private function resolveUrl(string $url, string $baseUrl): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (str_starts_with($url, '//')) {
            return "{$scheme}:{$url}";
        }

        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$port}{$url}";
        }

        $path = $base['path'] ?? '/';
        $dir = rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/');

        return "{$scheme}://{$host}{$port}{$dir}/{$url}";
    }

    /**
     * Descarga la imagen og:image a Storage::disk('public'), validando
     * Content-Type Y magic bytes (getimagesizefromstring detecta el formato
     * real por contenido, no por lo que declara el servidor).
     */
    private function downloadImage(string $imageUrl, string $baseUrl, string $urlHash): ?string
    {
        try {
            $result = $this->fetchImage($imageUrl);
        } catch (PublicUrlRejectedException) {
            return null;
        }

        if (strlen($result->body) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        $imageInfo = @getimagesizefromstring($result->body);
        if ($imageInfo === false) {
            return null;
        }

        $extension = match ($imageInfo['mime'] ?? null) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };

        if ($extension === null) {
            return null;
        }

        $path = 'link-previews/'.$urlHash.'.'.$extension;
        Storage::disk('public')->put($path, $result->body);

        return $path;
    }

    private function fetchImage(string $imageUrl): PublicUrlFetchResult
    {
        return $this->urlGuard->fetch($imageUrl, requireContentType: 'image/', maxBytes: self::MAX_IMAGE_BYTES);
    }
}
