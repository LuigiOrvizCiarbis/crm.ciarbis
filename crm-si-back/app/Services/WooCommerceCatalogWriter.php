<?php

namespace App\Services;

use App\Enums\ContactFieldType;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Escribe en el catálogo local lo que llega de WooCommerce. Woo es la fuente de
 * verdad: los productos se identifican por source='woocommerce' + external_id y
 * cada sync pisa los datos que gestiona (columnas propias + MANAGED_FIELDS).
 */
class WooCommerceCatalogWriter
{
    public const SOURCE = 'woocommerce';

    /**
     * Campos del catálogo que completa el sync. Viven en custom_data para que la
     * búsqueda, los filtros y la IA los usen como cualquier otro campo.
     *
     * @var array<string, array{label: string, type: ContactFieldType}>
     */
    public const MANAGED_FIELDS = [
        'sku' => ['label' => 'SKU', 'type' => ContactFieldType::Text],
        'stock' => ['label' => 'Stock', 'type' => ContactFieldType::Number],
        'stock_status' => ['label' => 'Disponibilidad', 'type' => ContactFieldType::Select],
        'categories' => ['label' => 'Categorías', 'type' => ContactFieldType::MultiSelect],
        'image_url' => ['label' => 'Imagen', 'type' => ContactFieldType::Url],
        'product_url' => ['label' => 'Link en la tienda', 'type' => ContactFieldType::Url],
    ];

    /** Columnas de products que gestiona el sync en productos de WooCommerce. */
    public const MANAGED_COLUMNS = ['name', 'price', 'description', 'is_active'];

    private const STOCK_STATUS_LABELS = [
        'instock' => 'En stock',
        'outofstock' => 'Sin stock',
        'onbackorder' => 'Por encargo',
    ];

    private const MAX_CHOICE_LENGTH = 120;

    /**
     * Claves que el sync pisa en los productos de WooCommerce del tenant: las
     * columnas propias y los campos gestionados que existen con el tipo
     * esperado. Un campo propio del tenant con la misma clave y otro tipo no lo
     * escribe el sync (ver prepareFields), así que sigue siendo editable.
     *
     * @return list<string>
     */
    public static function managedKeys(int $tenantId): array
    {
        $fields = ProductField::forTenant($tenantId)->keyBy('key');
        $customKeys = array_filter(
            array_keys(self::MANAGED_FIELDS),
            fn (string $key): bool => $fields->get($key)?->type === self::MANAGED_FIELDS[$key]['type'],
        );

        return [...self::MANAGED_COLUMNS, ...$customKeys];
    }

    /**
     * Deja listos los campos gestionados: crea los que faltan y suma a las
     * opciones de los select los valores nuevos (p. ej. una categoría nueva).
     * No resucita campos que el tenant borró ni pisa uno propio con otro tipo:
     * esos se omiten y se informan como skipped.
     *
     * @param  list<array<string, mixed>>  $items  Productos de la página.
     * @return array{fields: array<string, ProductField>, skipped: list<string>}
     */
    public function prepareFields(int $tenantId, array $items): array
    {
        $existing = ProductField::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('key', array_keys(self::MANAGED_FIELDS))
            ->get()
            ->keyBy('key');

        $categories = [];
        foreach ($items as $item) {
            array_push($categories, ...$this->categoryNames($item));
        }
        $requiredChoices = [
            'stock_status' => array_values(self::STOCK_STATUS_LABELS),
            'categories' => array_values(array_unique($categories)),
        ];

        $fields = [];
        $skipped = [];
        foreach (self::MANAGED_FIELDS as $key => $definition) {
            $field = $existing->get($key);

            if ($field?->trashed()) {
                continue;
            }

            if ($field && $field->type !== $definition['type']) {
                $skipped[] = $field->label;

                continue;
            }

            $field ??= $this->createField($tenantId, $key, $definition);
            $this->ensureChoices($field, $requiredChoices[$key] ?? []);
            $fields[$key] = $field;
        }

        return ['fields' => $fields, 'skipped' => $skipped];
    }

    /**
     * Crea o actualiza el producto local de un item de /products.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, ProductField>  $fields  Campos gestionados utilizables.
     */
    public function upsertProduct(int $tenantId, array $item, array $fields, string $runId): Product
    {
        $externalId = (string) $item['id'];

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first()
            ?? new Product(['tenant_id' => $tenantId, 'source' => self::SOURCE, 'external_id' => $externalId]);

        $product->fill([
            'name' => Str::limit($this->text($item['name'] ?? null) ?? 'Sin nombre', Product::NAME_MAX_LENGTH, ''),
            'price' => $this->price($item['price'] ?? null),
            'description' => $this->description($item),
            // Solo se piden productos publicados: si llegó, está activo.
            'is_active' => true,
            'external_type' => Str::limit((string) ($item['type'] ?? 'simple'), 40, ''),
            'requires_shipping' => ! ($item['virtual'] ?? false),
            'external_sync_run_id' => $runId,
            'custom_data' => array_replace($product->custom_data ?? [], $this->managedValues($item, $fields)),
        ]);
        $product->save();

        return $product;
    }

    /**
     * Crea o actualiza un solo producto (con sus variaciones) fuera de un sync
     * completo, p. ej. desde un webhook de WooCommerce.
     *
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $variations
     */
    public function upsertSingle(int $tenantId, array $item, array $variations, string $runId): Product
    {
        $prepared = $this->prepareFields($tenantId, [$item]);
        $product = $this->upsertProduct($tenantId, $item, $prepared['fields'], $runId);
        $this->syncVariants($product, $variations);

        return $product;
    }

    /**
     * Reemplaza las variantes del producto por las variaciones publicadas que
     * devolvió Woo. Las que ya no existen se borran.
     *
     * @param  list<array<string, mixed>>  $variations
     */
    public function syncVariants(Product $product, array $variations): int
    {
        $kept = [];
        foreach ($variations as $variation) {
            $externalId = (string) ($variation['id'] ?? '');
            if ($externalId === '' || ($variation['status'] ?? 'publish') !== 'publish') {
                continue;
            }

            ProductVariant::withoutGlobalScopes()->updateOrCreate(
                ['product_id' => $product->id, 'external_id' => $externalId],
                [
                    'tenant_id' => $product->tenant_id,
                    'sku' => $this->text($variation['sku'] ?? null),
                    'attribute_values' => $this->variationAttributes($variation),
                    'price' => $this->price($variation['price'] ?? null),
                    'stock_quantity' => $this->stockQuantity($variation),
                    'stock_status' => $this->text($variation['stock_status'] ?? null),
                    'image_url' => $this->text($variation['image']['src'] ?? null),
                ],
            );
            $kept[] = $externalId;
        }

        ProductVariant::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->whereNotIn('external_id', $kept)
            ->delete();

        return count($kept);
    }

    /**
     * Desactiva los productos de Woo que no aparecieron en la corrida $runId
     * (borrados, despublicados o pasados a borrador en la tienda). No se borran
     * para no perder el historial que los referencia.
     */
    public function deactivateMissing(int $tenantId, string $runId): int
    {
        return Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source', self::SOURCE)
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('external_sync_run_id')->orWhere('external_sync_run_id', '!=', $runId))
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /**
     * @param  array{label: string, type: ContactFieldType}  $definition
     */
    private function createField(int $tenantId, string $key, array $definition): ProductField
    {
        $type = $definition['type'];

        return ProductField::create([
            'tenant_id' => $tenantId,
            'key' => $key,
            'label' => $definition['label'],
            'type' => $type,
            'options' => $type->requiresOptions() ? ['choices' => []] : null,
            'is_required' => false,
            'is_unique' => false,
            'display_order' => (int) ProductField::withoutGlobalScopes()->where('tenant_id', $tenantId)->max('display_order') + 1,
        ]);
    }

    /**
     * Un valor de select que no está entre las opciones del campo no pasa la
     * validación al editar el producto: se agregan antes de escribirlo.
     *
     * @param  list<string>  $choices
     */
    private function ensureChoices(ProductField $field, array $choices): void
    {
        if (! in_array($field->type, [ContactFieldType::Select, ContactFieldType::MultiSelect], true)) {
            return;
        }

        $options = $field->options ?? [];
        $current = array_values(array_filter((array) ($options['choices'] ?? []), 'is_string'));
        $missing = array_values(array_diff($choices, $current));

        if ($missing === []) {
            return;
        }

        $options['choices'] = [...$current, ...$missing];
        $field->options = $options;
        $field->save();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, ProductField>  $fields
     * @return array<string, mixed>
     */
    private function managedValues(array $item, array $fields): array
    {
        $categories = $this->categoryNames($item);

        $values = [
            'sku' => $this->text($item['sku'] ?? null),
            'stock' => $this->stockQuantity($item),
            'stock_status' => self::STOCK_STATUS_LABELS[$item['stock_status'] ?? ''] ?? null,
            'categories' => $categories === [] ? null : $categories,
            'image_url' => $this->text($item['images'][0]['src'] ?? null),
            'product_url' => $this->text($item['permalink'] ?? null),
        ];

        return array_intersect_key($values, $fields);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function categoryNames(array $item): array
    {
        $names = [];
        foreach ((array) ($item['categories'] ?? []) as $category) {
            $name = is_array($category) ? $this->text($category['name'] ?? null) : null;
            if ($name !== null) {
                $names[] = Str::limit($name, self::MAX_CHOICE_LENGTH, '');
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $variation
     * @return array<string, string>
     */
    private function variationAttributes(array $variation): array
    {
        $attributes = [];
        foreach ((array) ($variation['attributes'] ?? []) as $attribute) {
            $name = is_array($attribute) ? $this->text($attribute['name'] ?? null) : null;
            if ($name !== null) {
                // Opción vacía = el atributo acepta cualquier valor en Woo.
                $attributes[$name] = $this->text($attribute['option'] ?? null) ?? '';
            }
        }

        return $attributes;
    }

    /**
     * Stock numérico solo si la tienda lo gestiona (en variaciones, manage_stock
     * puede valer 'parent': el stock lo gestiona el producto padre).
     *
     * @param  array<string, mixed>  $item
     */
    private function stockQuantity(array $item): ?int
    {
        $quantity = $item['stock_quantity'] ?? null;

        return ($item['manage_stock'] ?? false) !== false && is_numeric($quantity) ? (int) $quantity : null;
    }

    private function price(mixed $price): ?float
    {
        return is_numeric($price) ? (float) $price : null;
    }

    /**
     * Descripción corta (o la larga como fallback) sin HTML: Woo devuelve HTML.
     *
     * @param  array<string, mixed>  $item
     */
    private function description(array $item): ?string
    {
        $text = $this->plainText($item['short_description'] ?? null) ?? $this->plainText($item['description'] ?? null);

        return $text === null ? null : Str::limit($text, Product::DESCRIPTION_MAX_LENGTH, '');
    }

    private function plainText(mixed $html): ?string
    {
        return is_string($html) ? $this->text(Str::of($html)->stripTags()->squish()->value()) : null;
    }

    /**
     * Texto plano: Woo devuelve nombres con entidades HTML (&amp;, &#8211;…).
     */
    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : $text;
    }
}
