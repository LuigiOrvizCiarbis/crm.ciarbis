<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContactFieldType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductFieldRequest;
use App\Http\Requests\UpdateProductFieldRequest;
use App\Models\ProductField;
use App\Support\ProductFieldRegistry;
use App\Support\RepeaterFieldSchema;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductFieldController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('product_fields.view') && ! $user?->can('product_fields.manage')) {
            abort(403);
        }

        $fields = ProductField::query()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductField $f): array => $this->serialize($f))
            ->all();

        return response()->json([
            'data' => $fields,
            'standard' => ProductFieldRegistry::standard(),
        ]);
    }

    public function store(StoreProductFieldRequest $request): JsonResponse
    {
        $type = ContactFieldType::from((string) $request->string('type'));
        $tenantId = $request->user()->tenant_id;
        $label = (string) $request->string('label');

        $attributes = [
            'tenant_id' => $tenantId,
            'label' => $label,
            'type' => $type,
            'options' => match (true) {
                $type === ContactFieldType::Repeater => RepeaterFieldSchema::normalize((array) $request->input('options', [])),
                $type === ContactFieldType::Currency => ['currency' => $this->normalizeCurrency($request->input('options.currency'))],
                $type->requiresOptions() => $request->input('options'),
                default => null,
            },
            'is_required' => (bool) $request->boolean('is_required'),
            'is_unique' => $type === ContactFieldType::Repeater ? false : (bool) $request->boolean('is_unique'),
            'display_order' => (int) ($request->input('display_order')
                ?? ((int) ProductField::query()->max('display_order') + 1)),
        ];

        // The generated key is checked and inserted under retry: a concurrent
        // request can grab the same candidate between the existence check and the
        // insert, so we catch the unique-constraint violation and try the next key.
        $field = $this->createWithUniqueKey($label, $tenantId, $attributes);

        return response()->json(['data' => $this->serialize($field)], 201);
    }

    public function update(UpdateProductFieldRequest $request, ProductField $productField): JsonResponse
    {
        $payload = $request->only(['label', 'options', 'is_required', 'is_unique', 'display_order']);

        if ($productField->type === ContactFieldType::Repeater) {
            $payload['is_unique'] = false;
            if (isset($payload['options'])) {
                $payload['options'] = RepeaterFieldSchema::normalize(
                    (array) $payload['options'],
                    $productField->options,
                );
            }
        } elseif ($productField->type === ContactFieldType::Currency) {
            if (array_key_exists('options', $payload)) {
                $payload['options'] = ['currency' => $this->normalizeCurrency(
                    $request->input('options.currency') ?? ($productField->options['currency'] ?? null),
                )];
            }
        } elseif (! $productField->type->requiresOptions()) {
            unset($payload['options']);
        }

        $productField->update($payload);

        return response()->json(['data' => $this->serialize($productField->refresh())]);
    }

    public function destroy(Request $request, ProductField $productField): JsonResponse
    {
        if (! $request->user()?->can('product_fields.manage')) {
            abort(403);
        }

        $productField->delete();

        return response()->json(['message' => 'Campo eliminado.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        if (! $request->user()?->can('product_fields.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.display_order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['items'] as $row) {
                ProductField::where('id', $row['id'])->update(['display_order' => $row['display_order']]);
            }
        });

        ProductField::clearTenantCache($request->user()->tenant_id);

        return response()->json(['message' => 'Orden actualizado.']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWithUniqueKey(string $label, int $tenantId, array $attributes): ProductField
    {
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $key = $this->generateUniqueKey($label, $tenantId);

            try {
                return ProductField::create([...$attributes, 'key' => $key]);
            } catch (QueryException $e) {
                // 23505 = unique_violation: a concurrent request took the key first.
                // Retry to pick the next candidate; rethrow anything else.
                if ($attempt === $maxAttempts || ! $this->isUniqueViolation($e)) {
                    throw $e;
                }
            }
        }

        // Unreachable — the loop either returns or throws.
        throw new \RuntimeException('Unable to generate a unique product field key.');
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->getCode() === '23505')
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    private function generateUniqueKey(string $label, int $tenantId): string
    {
        $base = Str::slug($label, '_');
        if ($base === '') {
            $base = 'field';
        }
        // Leave headroom for the "_N" collision suffix so the final key stays within budget.
        $base = substr($base, 0, 50);

        $reserved = ProductFieldRegistry::reservedKeys();
        $candidate = $base;
        $suffix = 1;

        while (
            in_array($candidate, $reserved, true)
            || ProductField::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('key', $candidate)
                ->exists()
        ) {
            $suffix++;
            $candidate = $base.'_'.$suffix;
        }

        return $candidate;
    }

    /**
     * Una divisa ausente o no soportada cae en el default en vez de fallar: la
     * validación ya rechazó lo inválido, y un campo sin divisa explícita es
     * legítimo desde el importador o un cliente viejo.
     */
    private function normalizeCurrency(mixed $currency): string
    {
        return is_string($currency) && in_array($currency, ContactFieldType::currencies(), true)
            ? $currency
            : ContactFieldType::DEFAULT_CURRENCY;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ProductField $field): array
    {
        return [
            'id' => $field->id,
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type->value,
            'options' => $field->options,
            'is_required' => $field->is_required,
            'is_unique' => $field->is_unique,
            'is_system' => false,
            'display_order' => $field->display_order,
            'created_at' => $field->created_at?->toIso8601String(),
            'updated_at' => $field->updated_at?->toIso8601String(),
        ];
    }
}
