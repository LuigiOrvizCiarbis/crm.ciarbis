<?php

namespace App\Services\Ai;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Obtiene candidatos con los índices del catálogo; el ranking final se hace
 * después, con las mismas reglas para PostgreSQL y SQLite.
 */
class PostgresCatalogCandidates
{
    private const SEARCH_VECTOR = "(to_tsvector('spanish'::regconfig, coalesce(name, '') || ' ' || coalesce(description, '')) || to_tsvector('spanish'::regconfig, coalesce(custom_data, '{}'::jsonb)))";

    private const CANDIDATE_LIMIT = 200;

    /**
     * @param  list<string>  $terms
     * @param  list<string>  $fieldKeys
     * @return array<int, Product>
     */
    public function find(int $tenantId, array $terms, array $fieldKeys): array
    {
        $candidates = [];
        $codeTerms = array_values(array_filter($terms, static fn (string $term): bool => (bool) preg_match('/\d/', $term)));
        $codeFields = array_intersect(['codigo', 'code', 'sku'], $fieldKeys);

        if ($codeTerms !== [] && $codeFields !== []) {
            $codes = $this->base($tenantId)
                ->where(function (Builder $query) use ($codeTerms, $codeFields): void {
                    foreach ($codeFields as $field) {
                        foreach ($codeTerms as $term) {
                            $query->orWhereRaw('custom_data @> ?::jsonb', [json_encode([$field => $term])]);
                        }
                    }
                })
                ->limit(self::CANDIDATE_LIMIT)
                ->get();
            foreach ($codes as $product) {
                $candidates[$product->id] = $product;
            }
        }

        $searchTerms = array_slice($terms, 0, 8);
        $querySql = implode(' || ', array_fill(0, count($searchTerms), "plainto_tsquery('spanish'::regconfig, ?)"));
        $textMatches = $this->base($tenantId)
            ->selectRaw('ts_rank('.self::SEARCH_VECTOR.', ('.$querySql.')) AS search_rank', $searchTerms)
            ->whereRaw(self::SEARCH_VECTOR.' @@ ('.$querySql.')', $searchTerms)
            ->where(function (Builder $query) use ($searchTerms, $fieldKeys): void {
                foreach ($searchTerms as $term) {
                    $query->orWhereRaw("to_tsvector('spanish'::regconfig, coalesce(name, '') || ' ' || coalesce(description, '')) @@ plainto_tsquery('spanish'::regconfig, ?)", [$term]);
                    foreach ($fieldKeys as $key) {
                        $query->orWhereRaw("to_tsvector('spanish'::regconfig, coalesce(custom_data ->> ?, '')) @@ plainto_tsquery('spanish'::regconfig, ?)", [$key, $term]);
                    }
                }
            })
            ->orderByDesc('search_rank')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
        foreach ($textMatches as $product) {
            if (! isset($candidates[$product->id])) {
                $candidates[$product->id] = $product;
            }
        }

        if ($candidates !== []) {
            return $candidates;
        }

        // Solo ante cero coincidencias: buscar nombres escritos con errores.
        $fuzzyTerms = array_values(array_filter($searchTerms, static fn (string $term): bool => mb_strlen($term) >= 3));
        if ($fuzzyTerms === []) {
            return [];
        }
        $similarity = implode(', ', array_fill(0, count($fuzzyTerms), 'word_similarity(?, name)'));
        $fuzzy = $this->base($tenantId)
            ->where(function (Builder $query) use ($fuzzyTerms): void {
                foreach ($fuzzyTerms as $term) {
                    $query->orWhereRaw('? <% name', [$term]);
                }
            })
            ->orderByRaw('greatest('.$similarity.') DESC', $fuzzyTerms)
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
        foreach ($fuzzy as $product) {
            $candidates[$product->id] = $product;
        }

        return $candidates;
    }

    private function base(int $tenantId): Builder
    {
        return Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->select(['id', 'name', 'price', 'description', 'custom_data']);
    }
}
