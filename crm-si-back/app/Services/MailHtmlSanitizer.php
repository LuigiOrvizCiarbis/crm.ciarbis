<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class MailHtmlSanitizer
{
    /** @var list<string> */
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'code', 'del', 'div', 'em', 'h1', 'h2', 'h3',
        'h4', 'hr', 'i', 'img', 'li', 'ol', 'p', 'pre', 's', 'span', 'strong',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    /** @var list<string> */
    private const SAFE_STYLE_PROPERTIES = [
        'background-color', 'color', 'font-size', 'font-style', 'font-weight',
        'line-height', 'text-align', 'text-decoration', 'white-space',
    ];

    /**
     * @return array{html: ?string, has_remote_images: bool}
     */
    public function sanitize(?string $html): array
    {
        $html = trim((string) $html);
        if ($html === '') {
            return ['html' => null, 'has_remote_images' => false];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8" ?><div data-mail-root="true">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $root = $xpath->query('//*[@data-mail-root="true"]')->item(0);
        if (! $root instanceof DOMElement) {
            return ['html' => null, 'has_remote_images' => false];
        }

        $hasRemoteImages = false;
        $nodes = iterator_to_array($xpath->query('.//*', $root) ?: []);

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->unwrap($node);

                continue;
            }

            $this->sanitizeAttributes($node, $tag, $hasRemoteImages);
            if ($tag === 'blockquote' || str_contains(strtolower($node->getAttribute('class')), 'gmail_quote')) {
                $node->setAttribute('data-mail-quoted', 'true');
            }
            $node->removeAttribute('class');
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return [
            'html' => trim($result) ?: null,
            'has_remote_images' => $hasRemoteImages,
        ];
    }

    private function sanitizeAttributes(DOMElement $element, string $tag, bool &$hasRemoteImages): void
    {
        $allowed = match ($tag) {
            'a' => ['href', 'title'],
            'img' => ['src', 'alt', 'width', 'height'],
            'td', 'th' => ['colspan', 'rowspan', 'scope'],
            default => [],
        };

        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = $attribute->name;
        }

        foreach ($attributes as $name) {
            if ($name === 'style') {
                $style = $this->sanitizeStyle($element->getAttribute('style'));
                $style === '' ? $element->removeAttribute('style') : $element->setAttribute('style', $style);

                continue;
            }

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            if (! $this->isSafeUrl($element->getAttribute('href'), true)) {
                $element->removeAttribute('href');
            } else {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer nofollow');
            }
        }

        if ($tag === 'img' && $element->hasAttribute('src')) {
            $src = trim($element->getAttribute('src'));
            if (preg_match('#^https?://#i', $src)) {
                $hasRemoteImages = true;
                $element->setAttribute('data-remote-src', $src);
                $element->removeAttribute('src');
            } elseif (! str_starts_with(strtolower($src), 'data:image/')) {
                $element->removeAttribute('src');
            }
        }
    }

    private function sanitizeStyle(string $style): string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, null);
            $property = strtolower(trim((string) $property));
            $value = trim((string) $value);

            if ($property === '' || $value === '' || ! in_array($property, self::SAFE_STYLE_PROPERTIES, true)) {
                continue;
            }

            if (preg_match('/url\s*\(|expression\s*\(|javascript:/i', $value)) {
                continue;
            }

            $safe[] = $property.': '.$value;
        }

        return implode('; ', $safe);
    }

    private function isSafeUrl(string $url, bool $allowMailto = false): bool
    {
        $scheme = strtolower((string) parse_url(trim($url), PHP_URL_SCHEME));

        return in_array($scheme, $allowMailto ? ['http', 'https', 'mailto'] : ['http', 'https'], true)
            || ($scheme === '' && ! str_starts_with(trim($url), '//'));
    }

    private function unwrap(DOMNode $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }
}
