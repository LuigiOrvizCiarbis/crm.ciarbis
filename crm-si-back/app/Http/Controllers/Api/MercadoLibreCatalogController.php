<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MercadoLibreListingResource;
use App\Models\MercadoLibreConnection;
use App\Models\MercadoLibreListing;
use App\Models\User;
use App\Services\MercadoLibre\MercadoLibreCategories;
use App\Services\MercadoLibre\MercadoLibreException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lectura del catálogo en Mercado Libre: estado de las publicaciones y
 * búsqueda de categorías y su ficha técnica para completar un producto.
 */
class MercadoLibreCatalogController extends Controller
{
    private const LISTINGS_PER_PAGE = 20;

    private const CATEGORY_SUGGESTIONS = 8;

    public function __construct(private readonly MercadoLibreCategories $categories) {}

    public function listings(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['needs_attention', ...MercadoLibreListing::NEEDS_ATTENTION, MercadoLibreListing::STATUS_SYNCED, MercadoLibreListing::STATUS_PENDING, MercadoLibreListing::STATUS_PAUSED])],
        ]);

        $status = $validated['status'] ?? null;
        $listings = MercadoLibreListing::query()
            ->with(['product', 'variant'])
            ->when($status === 'needs_attention', fn ($query) => $query->whereIn('status', MercadoLibreListing::NEEDS_ATTENTION))
            ->when($status !== null && $status !== 'needs_attention', fn ($query) => $query->where('status', $status))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(self::LISTINGS_PER_PAGE);

        return response()->json([
            'data' => MercadoLibreListingResource::collection($listings->items()),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'last_page' => $listings->lastPage(),
                'total' => $listings->total(),
            ],
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $connection = $this->connection($request);
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120']]);

        try {
            $suggestions = $this->categories->predict($connection, $validated['q'], self::CATEGORY_SUGGESTIONS);
        } catch (MercadoLibreException $e) {
            return $this->unavailable($e);
        }

        return response()->json([
            'data' => array_map(fn (array $category) => [
                'category_id' => $category['category_id'],
                'category_name' => $category['category_name'],
                'domain_name' => $category['domain_name'],
            ], $suggestions),
        ]);
    }

    public function attributes(Request $request, string $categoryId): JsonResponse
    {
        $connection = $this->connection($request);
        abort_unless(preg_match('/^[A-Z]{3}\d+$/', $categoryId) === 1, 404);

        try {
            $attributes = $this->categories->attributes($connection, $categoryId);
        } catch (MercadoLibreException $e) {
            return $this->unavailable($e);
        }

        $visible = array_filter($attributes, fn (array $attribute) => ! in_array($attribute['id'], MercadoLibreCategories::MANAGED_ATTRIBUTES, true));
        $transformed = array_map(fn (array $attribute) => [
            'id' => (string) $attribute['id'],
            'name' => (string) ($attribute['name'] ?? $attribute['id']),
            'value_type' => (string) ($attribute['value_type'] ?? 'string'),
            'required' => ! empty($attribute['tags']['required']) || ! empty($attribute['tags']['conditional_required']),
            'values' => self::options($attribute['values'] ?? []),
            'allowed_units' => self::options($attribute['allowed_units'] ?? []),
            'max_length' => isset($attribute['value_max_length']) ? (int) $attribute['value_max_length'] : null,
            'hint' => $attribute['hint'] ?? $attribute['tooltip'] ?? null,
        ], array_values($visible));

        usort($transformed, fn (array $a, array $b) => $b['required'] <=> $a['required']);

        return response()->json(['data' => $transformed]);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private static function options(mixed $options): array
    {
        return array_values(array_map(
            fn (array $option) => ['id' => (string) $option['id'], 'name' => (string) $option['name']],
            array_filter((array) $options, fn ($option) => is_array($option) && isset($option['id'], $option['name'])),
        ));
    }

    private function connection(Request $request): MercadoLibreConnection
    {
        $user = $this->authorizeView($request);
        $connection = MercadoLibreConnection::forTenant($user->tenant_id);

        abort_unless($connection?->isConnected(), 422, 'Conectá tu cuenta de Mercado Libre primero.');

        return $connection;
    }

    private function unavailable(MercadoLibreException $e): JsonResponse
    {
        return response()->json(
            ['message' => $e->isTransient() ? 'No pudimos comunicarnos con Mercado Libre. Probá de nuevo.' : $e->getMessage()],
            $e->isTransient() ? 503 : 422,
        );
    }

    private function authorizeView(Request $request): User
    {
        $user = $request->user();
        if (! $user?->can('products.view') && ! $user?->can('products.manage')) {
            abort(403);
        }

        return $user;
    }
}
