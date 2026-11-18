<?php

namespace App\Support;

/**
 * Resuelve un href (absoluto, protocol-relative, absoluto de path o relativo)
 * contra la URL de la página que lo contiene.
 */
final class UrlResolver
{
    public static function resolve(string $url, string $baseUrl): string
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
}
