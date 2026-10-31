<?php

namespace App\Rules;

use App\Models\Product;
use App\Models\ProductField;
use App\Enums\ContactFieldType;
use App\Support\RepeaterFieldSchema;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ValidProductCustomData implements ValidationRule
{
    /**
     * @param  list<string>|null  $providedKeys  Keys explicitly sent in the request payload. When provided, the
     *                                           required-field check is skipped for keys absent from this list so a
     *                                           partial update of a single cell does not fail on unrelated required
     *                                           fields that are empty on the product.
     */
    public function __construct(
        private ?int $ignoreProductId = null,
        private ?array $providedKeys = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            $value = [];
        }

        if (! is_array($value)) {
            $fail('productsPage.customFields.errors.invalidPayload')->translate();

            return;
        }

        $tenantId = Auth::user()?->tenant_id;

        if (! $tenantId) {
            return;
        }

        $fields = ProductField::forTenant($tenantId)->keyBy('key');

        foreach ($fields as $key => $field) {
            $present = array_key_exists($key, $value);
            $rawValue = $value[$key] ?? null;
            $sentInPayload = $this->providedKeys === null || in_array($key, $this->providedKeys, true);

            if ($sentInPayload && $field->is_required && (! $present || $rawValue === null || $rawValue === '' || (is_array($rawValue) && $rawValue === []))) {
                $fail("custom_data.{$key} es requerido.");

                continue;
            }

            if (! $present || $rawValue === null) {
                continue;
            }

            $rules = ['value' => $field->type->valueRules($field->options)];
            $itemRules = $field->type->itemRules($field->options);
            if ($itemRules !== null) {
                $rules['value.*'] = $itemRules;
            }

            $validator = Validator::make(['value' => $rawValue], $rules);

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $fail("custom_data.{$key}: {$message}");
                }

                continue;
            }

            if ($field->type === ContactFieldType::Repeater) {
                foreach (RepeaterFieldSchema::valueErrors($rawValue, $field->options ?? []) as $message) {
                    $fail("custom_data.{$key}: {$message}");
                }
                continue;
            }

            if ($field->is_unique) {
                // Compare against the JSON value directly so array/bool/scalar
                // types all match correctly. Casting arrays to string would
                // compare against the literal "Array" and break the check.
                $exists = Product::query()
                    ->where('id', '!=', $this->ignoreProductId ?? 0)
                    ->whereRaw('custom_data -> ? = ?::jsonb', [$key, json_encode($rawValue)])
                    ->exists();

                if ($exists) {
                    $fail("custom_data.{$key}: el valor ya existe para otro producto.");
                }
            }
        }
    }
}
