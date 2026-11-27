<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MercadoLibreListingResource;
use App\Jobs\SyncMercadoLibreProductJob;
use App\Models\MercadoLibreConnection;
use App\Models\MercadoLibreListing;
use App\Models\MercadoLibreProduct;
use App\Models\Product;
use App\Services\MercadoLibre\MercadoLibreCategories;
use App\Services\MercadoLibre\MercadoLibreException;
use App\Services\MercadoLibre\MercadoLibreProductSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cómo se publica un producto en Mercado Libre (categoría, atributos, si se
 * publica) y el estado de sus publicaciones.
 */
class ProductMercadoLibreController extends Controller
{
    public function __construct(
        private readonly MercadoLibreProductSettings $settings,
        private readonly MercadoLibreCategories $categories,
    ) {}

    public function show(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('products.view') && ! $user?->can('products.manage')) {
            abort(403);
        }
        abort_unless($product->tenant_id === $user->tenant_id, 404);

        $connection = MercadoLibreConnection::forTenant($product->tenant_id);

        return response()->json(['data' => $this->transform($product, $connection, $this->settingsFor($connection, $product))]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('products.manage')) {
            abort(403);
        }
        abort_unless($product->tenant_id === $user->tenant_id, 404);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'category_id' => ['present', 'nullable', 'string', 'regex:/^[A-Z]{3}\d+$/'],
            'attributes' => ['present', 'array', 'max:100'],
            'attributes.*.id' => ['required', 'string', 'max:60', 'distinct'],
            'attributes.*.value_id' => ['nullable', 'string', 'max:60'],
            'attributes.*.value_name' => ['required', 'string', 'max:255'],
        ]);

        $connection = MercadoLibreConnection::forTenant($product->tenant_id);
        if (! $connection?->isConnected()) {
            return response()->json(['message' => 'Conectá tu cuenta de Mercado Libre primero.'], 422);
        }

        $settings = MercadoLibreProduct::withoutGlobalScopes()->firstOrNew(
            ['product_id' => $product->id],
            ['tenant_id' => $product->tenant_id],
        );

        if ($validated['category_id'] !== $settings->category_id) {
            $settings->category_name = $validated['category_id'] === null ? null : $this->categoryName($connection, $validated['category_id']);
        }

        $settings->fill([
            'enabled' => $validated['enabled'],
            'category_id' => $validated['category_id'],
            // Quien guarda confirma la categoría, aunque sea la sugerida.
            'category_predicted' => false,
            'attribute_values' => MercadoLibreCategories::attributeValues($validated['attributes']),
        ])->save();

        if ($connection->isSyncing()) {
            MercadoLibreListing::withoutGlobalScopes()
                ->where('product_id', $product->id)
                ->update(['status' => MercadoLibreListing::STATUS_PENDING, 'payload_hash' => null, 'updated_at' => now()]);
            SyncMercadoLibreProductJob::dispatch($product->tenant_id, $product->id);
        }

        return response()->json(['data' => $this->transform($product, $connection, $settings->fresh())]);
    }

    /**
     * @throws ValidationException si ML no conoce la categoría.
     */
    private function categoryName(MercadoLibreConnection $connection, string $categoryId): string
    {
        try {
            $name = $this->categories->name($connection, $categoryId);
        } catch (MercadoLibreException $e) {
            throw ValidationException::withMessages(['category_id' => 'No pudimos validar la categoría con Mercado Libre. Probá de nuevo.']);
        }

        if ($name === null) {
            throw ValidationException::withMessages(['category_id' => 'Mercado Libre no reconoce esa categoría.']);
        }

        return $name;
    }

    /**
     * Ajustes guardados; con la cuenta conectada, además se predice la
     * categoría si falta. Si ML no responde se muestran sin predicción.
     */
    private function settingsFor(?MercadoLibreConnection $connection, Product $product): MercadoLibreProduct
    {
        if ($connection?->isConnected()) {
            try {
                return $this->settings->ensure($connection, $product);
            } catch (MercadoLibreException $e) {
                Log::info('No se pudo predecir la categoría de Mercado Libre', ['product_id' => $product->id, 'error' => $e->getMessage()]);
            }
        }

        return MercadoLibreProduct::withoutGlobalScopes()->where('product_id', $product->id)->first()
            ?? new MercadoLibreProduct(['tenant_id' => $product->tenant_id, 'product_id' => $product->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(Product $product, ?MercadoLibreConnection $connection, MercadoLibreProduct $settings): array
    {
        $listings = MercadoLibreListing::withoutGlobalScopes()
            ->where('product_id', $product->id)
            ->with(['product', 'variant'])
            ->orderBy('product_variant_id')
            ->get();

        return [
            'connected' => (bool) $connection?->isConnected(),
            'enabled' => $settings->enabled,
            'category_id' => $settings->category_id,
            'category_name' => $settings->category_name,
            'category_predicted' => $settings->category_predicted,
            'attributes' => array_values((array) $settings->attribute_values),
            'listings' => MercadoLibreListingResource::collection($listings),
        ];
    }
}
