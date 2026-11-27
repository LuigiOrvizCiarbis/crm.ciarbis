<?php

namespace App\Services\MercadoLibre;

use App\Models\MercadoLibreConnection;
use App\Models\MercadoLibreProduct;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Ajustes de publicación de un producto. Si el producto todavía no tiene
 * categoría se le predice una desde el nombre, con los atributos que ML
 * deduce del texto (marca, modelo…) como valores iniciales.
 */
class MercadoLibreProductSettings
{
    public function __construct(private readonly MercadoLibreCategories $categories) {}

    /**
     * @throws MercadoLibreException si ML no respondió (vale la pena reintentar).
     */
    public function ensure(MercadoLibreConnection $connection, Product $product): MercadoLibreProduct
    {
        $settings = MercadoLibreProduct::withoutGlobalScopes()->firstOrNew(
            ['product_id' => $product->id],
            ['tenant_id' => $product->tenant_id],
        );

        if ($settings->category_id === null) {
            $this->predictCategory($connection, $product, $settings);
        }

        if ($settings->isDirty() || ! $settings->exists) {
            $settings->save();
        }

        return $settings;
    }

    /**
     * @throws MercadoLibreException
     */
    private function predictCategory(MercadoLibreConnection $connection, Product $product, MercadoLibreProduct $settings): void
    {
        try {
            $prediction = $this->categories->predict($connection, $product->name)[0] ?? null;
        } catch (MercadoLibreException $e) {
            if ($e->isTransient() || $e->reauth) {
                throw $e;
            }

            Log::info('Mercado Libre no pudo predecir la categoría', ['product_id' => $product->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($prediction === null) {
            return;
        }

        $known = array_column((array) $settings->attribute_values, 'id');
        $predicted = array_filter($prediction['attributes'], fn (array $attribute) => ! in_array($attribute['id'], $known, true));

        $settings->fill([
            'category_id' => $prediction['category_id'],
            'category_name' => $prediction['category_name'],
            'category_predicted' => true,
            'attribute_values' => [...(array) $settings->attribute_values, ...array_values($predicted)],
        ]);
    }
}
