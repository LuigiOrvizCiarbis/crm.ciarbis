<?php

namespace App\Services\Ai;

use App\Exceptions\WebsiteContextException;
use App\Support\PublicUrlGuard;
use App\Support\PublicUrlRejectedException;
use App\Support\UrlResolver;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lee el texto de un sitio web para generar la "información del sitio" del
 * asistente: la URL ingresada más hasta N páginas internas del mismo dominio
 * enlazadas desde ella (profundidad 1), priorizando contacto, servicios,
 * sucursales, envíos, precios y políticas.
 *
 * Solo GET HTML vía PublicUrlGuard (anti-SSRF): los sitios que arman el
 * contenido con JavaScript devuelven poco texto y se rechazan con
 * content_insufficient para que el tenant cargue los datos a mano.
 */
class WebsiteCrawler
{
    private const MAX_PAGE_CHARS = 6000;

    private const MAX_TOTAL_CHARS = 40000;

    private const MIN_TOTAL_CHARS = 500;

    private const MAX_JSON_LD_CHARS = 4000;

    private const STRIPPED_NODES = '//script|//style|//noscript|//svg|//iframe|//template';

    private const CONTACT_PREFIXES = ['tel:', 'mailto:', 'https://wa.me/', 'https://api.whatsapp.com/'];

    private const SKIPPED_EXTENSIONS = '/\.(pdf|jpe?g|png|gif|webp|svg|zip|mp4|mp3|docx?|xlsx?)$/';

    private const PRIORITY_KEYWORDS = [
        'contact', 'contacto', 'nosotros', 'about', 'quienes', 'empresa', 'servicio', 'service',
        'producto', 'product', 'pregunta', 'faq', 'ayuda', 'help', 'sucursal', 'local', 'ubicacion',
        'donde', 'horario', 'envio', 'shipping', 'entrega', 'precio', 'pricing', 'tarifa', 'plan',
        'politica', 'devolucion', 'cambio', 'garantia', 'termino',
    ];

    public function __construct(private PublicUrlGuard $urlGuard) {}

    /**
     * La primera página devuelta es siempre la URL ingresada (ya resueltos sus
     * redirects).
     *
     * @return list<array{url: string, title: string, text: string}>
     *
     * @throws WebsiteContextException
     */
    public function crawl(string $url): array
    {
        $startedAt = microtime(true);
        $budgetSeconds = (float) config('services.ai.website_context.crawl_budget_seconds', 25);

        try {
            $start = $this->urlGuard->fetch($url, requireContentType: 'text/html');
        } catch (PublicUrlRejectedException $exception) {
            throw new WebsiteContextException($exception->getMessage(), 'url_rejected', 422);
        }

        $startPage = $this->extractPage($start->body, $start->finalUrl);
        $pages = [];
        $totalChars = 0;
        $seen = [$this->dedupeKey($start->finalUrl) => true];
        $this->addPage($pages, $totalChars, $start->finalUrl, $startPage, keepEmpty: true);

        foreach ($this->internalLinks($startPage['links'], $start->finalUrl) as $link) {
            if ($totalChars >= self::MAX_TOTAL_CHARS || microtime(true) - $startedAt > $budgetSeconds) {
                break;
            }

            try {
                $result = $this->urlGuard->fetch($link, requireContentType: 'text/html');
            } catch (PublicUrlRejectedException $exception) {
                Log::info('WebsiteCrawler: página omitida', ['url' => $link, 'error' => $exception->getMessage()]);

                continue;
            }

            // Un redirect puede llevar a una página ya leída (p. ej. al home).
            $key = $this->dedupeKey($result->finalUrl);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $this->addPage($pages, $totalChars, $result->finalUrl, $this->extractPage($result->body, $result->finalUrl));
        }

        if ($totalChars < self::MIN_TOTAL_CHARS) {
            throw new WebsiteContextException(
                'No pudimos leer contenido de este sitio. Puede que se arme con JavaScript: cargá la información a mano.',
                'content_insufficient',
                422,
            );
        }

        return $pages;
    }

    /**
     * @param  list<array{url: string, title: string, text: string}>  $pages
     * @param  array{title: string, text: string, links: list<array{url: string, label: string}>}  $page
     */
    private function addPage(array &$pages, int &$totalChars, string $url, array $page, bool $keepEmpty = false): void
    {
        $text = mb_substr($page['text'], 0, max(0, self::MAX_TOTAL_CHARS - $totalChars));
        if ($text === '' && ! $keepEmpty) {
            return;
        }

        $pages[] = ['url' => $url, 'title' => $page['title'], 'text' => $text];
        $totalChars += mb_strlen($text);
    }

    /**
     * @return array{title: string, text: string, links: list<array{url: string, label: string}>}
     */
    private function extractPage(string $html, string $finalUrl): array
    {
        $xpath = new DOMXPath($this->loadDocument($html));

        $jsonLd = $this->jsonLd($xpath);
        [$links, $contacts] = $this->anchors($xpath, $finalUrl);
        $this->removeNodes($xpath);

        $titleNode = $xpath->query('//title')->item(0);
        $description = $xpath->query('//meta[translate(@name, "DESCRIPTION", "description")="description"]/@content')->item(0);

        // Descripción, contactos y JSON-LD son densos en datos útiles: el
        // recorte por página se lo lleva el texto del body.
        $structured = array_filter([
            $description ? $this->collapse($description->nodeValue ?? '') : '',
            $contacts !== [] ? 'Contacto: '.implode(' | ', $contacts) : '',
            $jsonLd,
        ]);
        $structuredText = implode("\n", $structured);
        $bodyBudget = max(0, self::MAX_PAGE_CHARS - mb_strlen($structuredText) - 1);
        $bodyText = mb_substr($this->bodyText($xpath), 0, $bodyBudget);

        $text = trim(implode("\n", array_filter([$bodyText, $structuredText])));

        return [
            'title' => $titleNode ? $this->collapse($titleNode->textContent) : '',
            'text' => mb_substr($text, 0, self::MAX_PAGE_CHARS),
            'links' => $links,
        ];
    }

    private function loadDocument(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Sin la declaración de encoding, loadHTML() asume Latin-1 y corrompe
        // cualquier tilde/ñ del HTML (mismo fix que LinkPreviewService).
        $document->loadHTML(
            '<?xml encoding="utf-8" ?>'.$html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * Datos estructurados (schema.org): suelen traer horarios y direcciones.
     */
    private function jsonLd(DOMXPath $xpath): string
    {
        $chunks = [];
        $used = 0;

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $script) {
            $chunk = mb_substr($this->collapse($script->textContent), 0, self::MAX_JSON_LD_CHARS - $used);
            if ($chunk === '') {
                continue;
            }
            $chunks[] = $chunk;
            $used += mb_strlen($chunk);
            if ($used >= self::MAX_JSON_LD_CHARS) {
                break;
            }
        }

        return $chunks === [] ? '' : 'Datos estructurados: '.implode(' ', $chunks);
    }

    /**
     * @return array{0: list<array{url: string, label: string}>, 1: list<string>}
     */
    private function anchors(DOMXPath $xpath, string $finalUrl): array
    {
        $links = [];
        $contacts = [];

        foreach ($xpath->query('//a[@href]') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            $label = $this->collapse($anchor->textContent);

            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }

            if ($this->isContactHref($href)) {
                $contacts[$href] = $label !== '' ? "{$label}: {$href}" : $href;

                continue;
            }

            $links[] = ['url' => UrlResolver::resolve($href, $finalUrl), 'label' => $label];
        }

        return [$links, array_values($contacts)];
    }

    private function isContactHref(string $href): bool
    {
        $href = strtolower($href);

        foreach (self::CONTACT_PREFIXES as $prefix) {
            if (str_starts_with($href, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function removeNodes(DOMXPath $xpath): void
    {
        /** @var list<DOMNode> $nodes */
        $nodes = iterator_to_array($xpath->query(self::STRIPPED_NODES));

        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Junta los nodos de texto con espacios: textContent pegaría el texto de
     * elementos vecinos ("InicioContacto").
     */
    private function bodyText(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//body//text()');
        if ($nodes->length === 0) {
            $nodes = $xpath->query('//text()');
        }

        $parts = [];
        foreach ($nodes as $node) {
            $parts[] = $node->nodeValue;
        }

        return $this->collapse(implode(' ', $parts));
    }

    /**
     * Links internos a leer, prioritarios primero y cada grupo en orden de
     * aparición.
     *
     * @param  list<array{url: string, label: string}>  $links
     * @return list<string>
     */
    private function internalLinks(array $links, string $startUrl): array
    {
        $host = $this->normalizedHost($startUrl);
        $seen = [$this->dedupeKey($startUrl) => true];
        $priority = [];
        $rest = [];

        foreach ($links as $link) {
            $url = explode('#', $link['url'], 2)[0];
            $parts = parse_url($url) ?: [];
            $scheme = strtolower($parts['scheme'] ?? '');
            $path = strtolower($parts['path'] ?? '/');
            $key = $this->dedupeKey($url);

            if (! in_array($scheme, ['http', 'https'], true)
                || $this->normalizedHost($url) !== $host
                || isset($seen[$key])
                || preg_match(self::SKIPPED_EXTENSIONS, $path)) {
                continue;
            }
            $seen[$key] = true;

            if (Str::contains($path.' '.Str::ascii(mb_strtolower($link['label'])), self::PRIORITY_KEYWORDS)) {
                $priority[] = $url;
            } else {
                $rest[] = $url;
            }
        }

        $maxPages = max(0, (int) config('services.ai.website_context.max_pages', 10));

        return array_slice([...$priority, ...$rest], 0, $maxPages);
    }

    private function normalizedHost(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * "https://x.com/a/" y "https://x.com/a#top" son la misma página.
     */
    private function dedupeKey(string $url): string
    {
        return rtrim(explode('#', $url, 2)[0], '/');
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
