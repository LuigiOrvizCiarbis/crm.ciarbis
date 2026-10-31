<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\ProductField;
use App\Models\Scopes\TenantScope;
use App\Services\AiReplyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Busca productos del tenant sin cargar el catálogo completo en memoria ni
 * depender de un proveedor externo de embeddings.
 */
class CatalogProductSearch
{
    public function __construct(private readonly PostgresCatalogCandidates $postgresCandidates) {}

    private const STOP_WORDS = [
        'a', 'al', 'algo', 'con', 'cuanto', 'cuesta', 'de', 'del', 'el', 'en', 'es',
        'hay', 'hola', 'la', 'las', 'lo', 'los', 'me', 'para', 'por', 'precio',
        'producto', 'productos', 'que', 'quiero', 'se', 'su', 'sus', 'tenes',
        'tienen', 'un', 'una', 'y',
    ];

    /**
     * @param  array<int, array{role: string, content: string|array<int, array<string, mixed>>}>  $messages
     * @return array{products: list<Product>, matches: int, active: int, fields: array<string, string>, catalog_question: bool, broad: bool}
     */
    public function search(int $tenantId, array $messages, int $limit): array
    {
        $customerTexts = $this->customerTexts($messages);
        $latest = $customerTexts[0] ?? '';
        $latestTerms = $this->terms($latest);
        $normalizedLatest = $this->normalize($latest);
        $catalogQuestion = (bool) preg_match('/\b(catalogo|productos?|articulos?|precios?|venden|tenes|tienen|busco|comprar|marca|cuanto cuesta)\b/u', $normalizedLatest);
        $isFollowUp = (bool) preg_match('/\b(ese|esa|esos|esas|el de|la de|y el|y la|mismo|misma|tambien)\b/u', $normalizedLatest)
            || (count($latestTerms) <= 2 && (bool) preg_match('/\b\d+\b|\b(kg|gr|ml|litro|talle)\b/u', $normalizedLatest))
            || ($latestTerms === [] && $catalogQuestion);
        $previousTerms = $isFollowUp ? $this->terms($customerTexts[1] ?? '') : [];
        $terms = array_values(array_unique([...$latestTerms, ...$previousTerms]));

        if ($terms === []) {
            return [
                'products' => [],
                'matches' => 0,
                'active' => Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->count(),
                'fields' => [],
                'catalog_question' => $catalogQuestion,
                'broad' => true,
            ];
        }

        $fields = ProductField::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get(['key', 'label'])
            ->pluck('label', 'key')
            ->all();

        $postgres = DB::getDriverName() === 'pgsql';
        $products = $postgres
            ? $this->postgresCandidates->find($tenantId, $terms, array_keys($fields))
            : Product::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->select(['id', 'name', 'price', 'description', 'custom_data'])
                ->orderBy('id')
                ->cursor();

        $best = [];
        $matches = 0;
        foreach ($products as $product) {
            $score = $this->score($product, $terms, $latestTerms, array_keys($fields));
            if ($score === 0) {
                if (! $postgres) {
                    continue;
                }
                // PostgreSQL puede hallar flexiones o errores ortográficos
                // aunque la coincidencia literal de PHP no lo haga.
                $score = 1;
            }

            $matches++;
            $best[] = ['product' => $product, 'score' => $score];
            usort($best, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
                ?: strcmp($a['product']->name, $b['product']->name)
                ?: $a['product']->id <=> $b['product']->id);
            if (count($best) > $limit) {
                array_pop($best);
            }
        }

        return [
            'products' => array_map(static fn (array $row): Product => $row['product'], $best),
            'matches' => $matches,
            'active' => Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->count(),
            'fields' => $fields,
            'catalog_question' => $catalogQuestion,
            'broad' => false,
        ];
    }

    /** @param list<string> $terms
     * @param  list<string>  $latestTerms
     * @param  list<string>  $fieldKeys
     */
    private function score(Product $product, array $terms, array $latestTerms, array $fieldKeys): int
    {
        $name = $this->normalize($product->name);
        $description = $this->normalize((string) $product->description);
        $custom = [];
        foreach ($fieldKeys as $key) {
            $value = ($product->custom_data ?? [])[$key] ?? null;
            if (is_scalar($value)) {
                $custom[$key] = $this->normalize((string) $value);
            } elseif (is_array($value)) {
                $custom[$key] = $this->normalize(implode(' ', array_filter($value, 'is_scalar')));
            }
        }

        $score = 0;
        foreach ($terms as $term) {
            $weight = in_array($term, $latestTerms, true) ? 2 : 1;
            foreach ($custom as $key => $value) {
                if ($value === '' || ! str_contains($value, $term)) {
                    continue;
                }
                $isCode = in_array($key, ['codigo', 'code', 'sku'], true);
                $score += $weight * ($isCode ? ($value === $term ? 200 : 60) : 15);
            }

            if (str_contains($name, $term)) {
                $score += $weight * ($name === $term ? 100 : 30);
            } elseif (str_contains($description, $term)) {
                $score += $weight * 5;
            }
        }

        if ($latestTerms !== [] && str_contains($name, implode(' ', $latestTerms))) {
            $score += 100;
        }

        return $score;
    }

    /**
     * @param  array<int, array{role: string, content: string|array<int, array<string, mixed>>}>  $messages
     * @return list<string>
     */
    private function customerTexts(array $messages): array
    {
        $texts = [];
        foreach (array_reverse($messages) as $message) {
            if ($message['role'] !== 'user') {
                continue;
            }
            $content = $message['content'];
            $text = is_string($content) ? $content : implode(' ', array_map(
                static fn (array $block): string => $block['type'] === 'text' ? (string) ($block['text'] ?? '') : '',
                $content,
            ));
            $text = trim(str_replace(AiReplyService::HISTORY_OVERRIDE_NOTE, '', $text));
            $text = trim(preg_replace('/^\[El cliente envió una imagen\]\s*/u', '', $text));
            if ($text === '') {
                // Una imagen sin texto no debe reutilizar como consulta el
                // mensaje anterior del cliente.
                break;
            }
            $texts[] = $text;
            if (count($texts) === 2) {
                break;
            }
        }

        return $texts;
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        $normalized = $this->normalize($text);
        preg_match_all('/\b[a-z0-9]+[-_][a-z0-9_-]+\b/', $normalized, $codes);
        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches);
        $terms = $codes[0];
        foreach ($matches[0] as $word) {
            if (mb_strlen($word) < 2 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }
            $term = mb_strlen($word) > 4 ? preg_replace('/s$/', '', $word) : $word;
            $terms[] = $term;
        }

        return array_values(array_unique($terms));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }
}
