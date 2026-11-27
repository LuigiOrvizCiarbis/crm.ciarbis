<?php

namespace App\Services\MercadoLibre;

use App\Models\MercadoLibreConnection;
use App\Models\MercadoLibreProduct;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Arma el ítem de Mercado Libre de una unidad vendible (el producto o una de
 * sus variantes) y detecta lo que falta para poder publicarlo. Cada variante
 * es un ítem propio: los vendedores en el modelo "User Products" las agrupan
 * en una familia por family_name; el resto las publica por separado.
 */
class MercadoLibreItemBuilder
{
    private const TITLE_MAX_LENGTH = 60;

    private const DESCRIPTION_MAX_LENGTH = 50000;

    private const MAX_PICTURES = 10;

    // Valores de stock_status: el de las variantes viene crudo de Woo; el del
    // producto, traducido por WooCommerceCatalogWriter.
    private const OUT_OF_STOCK = ['outofstock', 'Sin stock'];

    /**
     * @param  list<array<string, mixed>>  $categoryAttributes  ficha técnica de la categoría
     * @return array{payload: array<string, mixed>, description: ?string, quantity: int, missing: list<array{id: string, name: string}>}
     */
    public function build(
        MercadoLibreConnection $connection,
        Product $product,
        ?ProductVariant $variant,
        MercadoLibreProduct $settings,
        array $categoryAttributes,
    ): array {
        $price = $variant?->price ?? $product->price;
        $pictures = $this->pictures($product, $variant);
        $attributes = $this->attributes($product, $variant, $settings, $categoryAttributes);
        $quantity = $this->quantity($connection, $product, $variant);

        $payload = [
            ...$this->naming($connection, $product, $variant),
            'category_id' => $settings->category_id,
            'price' => $price === null ? null : (float) $price,
            'currency_id' => $connection->currency_id,
            'available_quantity' => $quantity,
            'buying_mode' => 'buy_it_now',
            'listing_type_id' => $connection->listing_type_id,
            'condition' => $connection->condition,
            'pictures' => array_map(fn (string $url) => ['source' => $url], $pictures),
            'attributes' => $attributes,
        ];

        return [
            'payload' => $payload,
            'description' => $this->description($product),
            'quantity' => $quantity,
            'missing' => $this->missing($settings, $price, $pictures, $attributes, $categoryAttributes),
        ];
    }

    /**
     * "Color: Rojo · Talle: M"
     */
    public static function variantLabel(?ProductVariant $variant): ?string
    {
        $values = (array) ($variant?->attribute_values ?? []);
        if ($values === []) {
            return null;
        }

        return implode(' · ', array_map(fn ($name, $value) => "{$name}: {$value}", array_keys($values), $values));
    }

    /**
     * @return array<string, string>
     */
    private function naming(MercadoLibreConnection $connection, Product $product, ?ProductVariant $variant): array
    {
        if ($connection->user_products) {
            // ML arma el título con family_name + los atributos de la variante.
            return ['family_name' => Str::limit($product->name, self::TITLE_MAX_LENGTH, '')];
        }

        $suffix = implode(' ', array_values((array) ($variant?->attribute_values ?? [])));

        return ['title' => Str::limit(trim($product->name.' '.$suffix), self::TITLE_MAX_LENGTH, '')];
    }

    /**
     * Atributos del ítem: los que cargó el usuario (o predijo ML), los de la
     * variante (Color, Talle…) y el SKU. Los de la variante se mapean a los de
     * la categoría por nombre; si la categoría no los tiene van como atributos
     * personalizados.
     *
     * @param  list<array<string, mixed>>  $categoryAttributes
     * @return list<array<string, ?string>>
     */
    private function attributes(Product $product, ?ProductVariant $variant, MercadoLibreProduct $settings, array $categoryAttributes): array
    {
        $byId = [];
        foreach ((array) $settings->attribute_values as $attribute) {
            $byId[$attribute['id']] = array_filter([
                'id' => $attribute['id'],
                'value_id' => $attribute['value_id'] ?? null,
                'value_name' => $attribute['value_name'],
            ], fn ($value) => $value !== null);
        }

        $custom = [];
        foreach ((array) ($variant?->attribute_values ?? []) as $name => $value) {
            $definition = $this->findAttribute($categoryAttributes, (string) $name);
            if ($definition === null) {
                $custom[] = ['name' => (string) $name, 'value_name' => (string) $value];

                continue;
            }

            $byId[$definition['id']] = array_filter([
                'id' => $definition['id'],
                'value_id' => $this->findValueId($definition, (string) $value),
                'value_name' => (string) $value,
            ], fn ($item) => $item !== null);
        }

        $sku = $variant?->sku ?? ($product->custom_data['sku'] ?? null);
        if (is_scalar($sku) && trim((string) $sku) !== '') {
            $byId['SELLER_SKU'] = ['id' => 'SELLER_SKU', 'value_name' => trim((string) $sku)];
        }

        return [...array_values($byId), ...$custom];
    }

    /**
     * @param  list<array<string, mixed>>  $categoryAttributes
     * @return ?array<string, mixed>
     */
    private function findAttribute(array $categoryAttributes, string $name): ?array
    {
        $needle = self::normalize($name);

        foreach ($categoryAttributes as $attribute) {
            if (self::normalize((string) ($attribute['name'] ?? '')) === $needle || self::normalize((string) $attribute['id']) === $needle) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function findValueId(array $definition, string $value): ?string
    {
        $needle = self::normalize($value);

        foreach ((array) ($definition['values'] ?? []) as $option) {
            if (is_array($option) && self::normalize((string) ($option['name'] ?? '')) === $needle) {
                return (string) $option['id'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function pictures(Product $product, ?ProductVariant $variant): array
    {
        $urls = array_filter(
            [$variant?->image_url, $product->custom_data['image_url'] ?? null],
            fn ($url) => is_string($url) && preg_match('#^https?://#i', $url) === 1,
        );

        return array_slice(array_values(array_unique($urls)), 0, self::MAX_PICTURES);
    }

    /**
     * Stock de la variante o del producto. Sin stock gestionado se publica la
     * cantidad por defecto de la conexión, salvo que figure "sin stock".
     */
    private function quantity(MercadoLibreConnection $connection, Product $product, ?ProductVariant $variant): int
    {
        $stock = $variant ? $variant->stock_quantity : ($product->custom_data['stock'] ?? null);
        $status = $variant ? $variant->stock_status : ($product->custom_data['stock_status'] ?? null);

        if (is_numeric($stock)) {
            return max(0, (int) $stock);
        }

        return in_array($status, self::OUT_OF_STOCK, true) ? 0 : $connection->default_quantity;
    }

    /**
     * Texto plano sin emojis (ML rechaza la descripción entera si trae uno).
     */
    private function description(Product $product): ?string
    {
        $text = Str::of((string) $product->description)
            ->replaceMatches('/<br\s*\/?>|<\/p>/i', "\n")
            ->stripTags()
            ->replaceMatches('/[\x{10000}-\x{10FFFF}]/u', '')
            ->trim()
            ->value();
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $text === '' ? null : Str::limit($text, self::DESCRIPTION_MAX_LENGTH, '');
    }

    /**
     * @param  list<string>  $pictures
     * @param  list<array<string, ?string>>  $attributes
     * @param  list<array<string, mixed>>  $categoryAttributes
     * @return list<array{id: string, name: string}>
     */
    private function missing(MercadoLibreProduct $settings, mixed $price, array $pictures, array $attributes, array $categoryAttributes): array
    {
        if (! $settings->category_id) {
            return [['id' => 'CATEGORY', 'name' => 'Categoría']];
        }

        $missing = [];
        if ($price === null || (float) $price <= 0) {
            $missing[] = ['id' => 'PRICE', 'name' => 'Precio'];
        }
        if ($pictures === []) {
            $missing[] = ['id' => 'PICTURE', 'name' => 'Imagen'];
        }

        $present = array_filter(array_column($attributes, 'id'));
        foreach (MercadoLibreCategories::required($categoryAttributes) as $attribute) {
            if (! in_array($attribute['id'], $present, true)) {
                $missing[] = ['id' => (string) $attribute['id'], 'name' => (string) ($attribute['name'] ?? $attribute['id'])];
            }
        }

        return $missing;
    }

    private static function normalize(string $value): string
    {
        return Str::of($value)->ascii()->lower()->squish()->value();
    }
}
