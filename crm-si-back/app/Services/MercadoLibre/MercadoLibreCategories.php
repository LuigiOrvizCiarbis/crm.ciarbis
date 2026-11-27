<?php

namespace App\Services\MercadoLibre;

use App\Models\MercadoLibreConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Categorías y atributos de Mercado Libre: predicción de categoría desde un
 * texto (domain_discovery) y ficha técnica de cada categoría, cacheada porque
 * cambia poco y el sync la consulta por cada producto.
 */
class MercadoLibreCategories
{
    private const ATTRIBUTES_TTL_SECONDS = 86400;

    /** Atributos que completa el sync: no se le piden al usuario. */
    public const MANAGED_ATTRIBUTES = ['SELLER_SKU', 'ITEM_CONDITION'];

    public function __construct(private readonly MercadoLibreClient $client) {}

    /**
     * Categorías sugeridas para un texto, la más probable primero. Cada una
     * trae los atributos que ML dedujo del texto (p. ej. la marca).
     *
     * @return list<array{category_id: string, category_name: string, domain_name: ?string, attributes: list<array{id: string, value_id: ?string, value_name: string}>}>
     *
     * @throws MercadoLibreException
     */
    public function predict(MercadoLibreConnection $connection, string $text, int $limit = 1): array
    {
        $query = Str::limit(trim($text), 120, '');
        if ($query === '') {
            return [];
        }

        $results = $this->client->get($connection, '/sites/'.rawurlencode($connection->site_id).'/domain_discovery/search', [
            'q' => $query,
            'limit' => $limit,
        ]);

        return array_values(array_map(fn (array $result) => [
            'category_id' => (string) $result['category_id'],
            'category_name' => (string) ($result['category_name'] ?? $result['category_id']),
            'domain_name' => isset($result['domain_name']) ? (string) $result['domain_name'] : null,
            'attributes' => self::attributeValues((array) ($result['attributes'] ?? [])),
        ], array_filter($results, fn ($result) => is_array($result) && ! empty($result['category_id']))));
    }

    /**
     * Nombre de la categoría; null si ML no la conoce.
     *
     * @throws MercadoLibreException si ML no respondió.
     */
    public function name(MercadoLibreConnection $connection, string $categoryId): ?string
    {
        try {
            $category = $this->client->get($connection, '/categories/'.rawurlencode($categoryId));
        } catch (MercadoLibreException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }

        return isset($category['name']) ? (string) $category['name'] : null;
    }

    /**
     * Ficha técnica de la categoría, sin los atributos de solo lectura.
     *
     * @return list<array<string, mixed>>
     *
     * @throws MercadoLibreException
     */
    public function attributes(MercadoLibreConnection $connection, string $categoryId): array
    {
        $key = 'mercadolibre_category_attributes:'.$categoryId;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $attributes = array_values(array_filter(
            $this->client->get($connection, '/categories/'.rawurlencode($categoryId).'/attributes'),
            fn ($attribute) => is_array($attribute) && isset($attribute['id']) && empty($attribute['tags']['read_only']),
        ));
        Cache::put($key, $attributes, self::ATTRIBUTES_TTL_SECONDS);

        return $attributes;
    }

    /**
     * Atributos que ML exige para publicar en la categoría.
     *
     * @param  list<array<string, mixed>>  $attributes
     * @return list<array<string, mixed>>
     */
    public static function required(array $attributes): array
    {
        return array_values(array_filter(
            $attributes,
            fn (array $attribute) => ! empty($attribute['tags']['required']) && ! in_array($attribute['id'], self::MANAGED_ATTRIBUTES, true),
        ));
    }

    /**
     * Normaliza una lista de atributos de ML ({id, value_id, value_name}).
     *
     * @param  array<mixed>  $attributes
     * @return list<array{id: string, value_id: ?string, value_name: string}>
     */
    public static function attributeValues(array $attributes): array
    {
        $values = [];
        foreach ($attributes as $attribute) {
            $name = is_array($attribute) ? trim((string) ($attribute['value_name'] ?? '')) : '';
            if ($name === '' || empty($attribute['id'])) {
                continue;
            }

            $values[] = [
                'id' => (string) $attribute['id'],
                'value_id' => filled($attribute['value_id'] ?? null) ? (string) $attribute['value_id'] : null,
                'value_name' => $name,
            ];
        }

        return $values;
    }
}
