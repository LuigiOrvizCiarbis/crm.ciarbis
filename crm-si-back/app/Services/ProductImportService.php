<?php

namespace App\Services;

use App\Enums\ContactFieldType;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\ProductImport;
use App\Support\ProductFieldRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductImportService
{
    /** @return array<string, mixed> */
    public function preview(UploadedFile $file, array $mapping, string $matchField = 'name'): array
    {
        $rows = $this->readRows($file->getRealPath());
        $dataRows = ($mapping['has_headers'] ?? true) === false ? $rows : array_slice($rows, 1);
        $identifierColumn = $matchField === 'name'
            ? ($mapping['name'] ?? null)
            : data_get($mapping, 'custom.'.Str::after($matchField, 'custom:'));
        if (! is_int($identifierColumn)) {
            throw ValidationException::withMessages(['mapping' => 'Debes mapear la columna usada como identificador.']);
        }
        $seen = []; $duplicates = [];
        foreach ($dataRows as $offset => $row) {
            $value = $this->normalize((string) ($row[$identifierColumn] ?? ''));
            if ($value === '') continue;
            if (isset($seen[$value])) $duplicates[] = $offset + (($mapping['has_headers'] ?? true) ? 2 : 1);
            $seen[$value] = true;
        }
        return [
            'total_rows' => count($dataRows),
            'sample_rows' => array_slice($dataRows, 0, 5),
            'duplicate_rows' => array_slice($duplicates, 0, 50),
            'proposed_fields' => count((array) ($mapping['proposed_fields'] ?? [])),
            'warnings' => $duplicates === [] ? [] : ['Hay identificadores repetidos en el archivo. Esas filas no se procesarán.'],
        ];
    }

    /** @return array<string, mixed> */
    public function runQueued(ProductImport $import): array
    {
        return DB::transaction(function () use ($import): array {
            $mapping = $this->resolveProposedFields($import);
            $result = $this->processRows(
                $this->readRows(Storage::disk('local')->path($import->file_path)),
                $mapping, $import->tenant_id, (int) $import->requested_by,
                $import->mode, $import->match_field, $import->preserve_empty,
            );
            $import->update(['mapping' => $mapping]);
            return $result;
        });
    }

    /** Legacy synchronous endpoint. */
    public function import(UploadedFile $file, array $mapping, int $tenantId, int $userId): array
    {
        return DB::transaction(fn (): array => $this->processRows(
            $this->readRows($file->getRealPath()), $mapping, $tenantId, $userId, 'create', 'name', true,
        ));
    }

    /** @param list<array<int, string|null>> $rows
     * @return array<string, mixed>
     */
    private function processRows(array $rows, array $mapping, int $tenantId, int $userId, string $mode, string $matchField, bool $preserveEmpty): array
    {
        $hasHeaders = ($mapping['has_headers'] ?? true) !== false;
        if ($hasHeaders) array_shift($rows);
        $fields = ProductField::forTenant($tenantId)->keyBy('key');
        $existing = $this->existingIndex($tenantId, $matchField);
        $seen = []; $created = 0; $updated = 0; $duplicates = 0; $errors = 0; $errorRows = [];

        foreach ($rows as $offset => $row) {
            $rowNumber = $offset + ($hasHeaders ? 2 : 1);
            $values = $this->rowValues($row, $mapping, $fields);
            $identity = $matchField === 'name'
                ? $this->normalize($values['name'])
                : $this->normalize((string) ($values['custom'][Str::after($matchField, 'custom:')] ?? ''));
            if ($identity === '') {
                $errors++; $errorRows[] = ['row' => $rowNumber, 'reason' => 'Falta el identificador seleccionado']; continue;
            }
            if (isset($seen[$identity])) {
                $errors++; $errorRows[] = ['row' => $rowNumber, 'reason' => 'Identificador repetido dentro del archivo']; continue;
            }
            $seen[$identity] = true;
            $product = $existing[$identity] ?? null;
            $validationError = $this->validateRowValues($values, $mapping, $fields, $tenantId, $product, $preserveEmpty);
            if ($validationError !== null) {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => $validationError];
                continue;
            }
            if ($values['name'] === '' && ! $product) {
                $errors++; $errorRows[] = ['row' => $rowNumber, 'reason' => 'Nombre vacío']; continue;
            }
            if (mb_strlen($values['name']) > 150) {
                $errors++; $errorRows[] = ['row' => $rowNumber, 'reason' => 'Nombre excede 150 caracteres']; continue;
            }
            if ($product && $mode === 'create') { $duplicates++; continue; }
            if (! $product && $mode === 'update') { $duplicates++; continue; }
            if ($product) {
                $this->updateProduct($product, $values, $mapping, $preserveEmpty);
                $updated++;
                continue;
            }
            Product::withoutGlobalScopes()->create([
                'tenant_id' => $tenantId, 'created_by' => $userId, 'name' => $values['name'],
                'price' => $values['price'], 'description' => $values['description'],
                'is_active' => $values['is_active'], 'custom_data' => $values['custom'], 'source' => 'import',
            ]);
            $created++;
        }
        return ['imported' => $created, 'created' => $created, 'updated' => $updated, 'duplicates' => $duplicates,
            'errors' => $errors, 'error_rows' => array_slice($errorRows, 0, 50),
            'total' => $created + $updated + $duplicates + $errors];
    }

    /**
     * Validates source values before any coercion can reach create/update.
     *
     * @param  \Illuminate\Support\Collection<string, ProductField>  $fields
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $mapping
     */
    private function validateRowValues(array $values, array $mapping, $fields, int $tenantId, ?Product $product, bool $preserveEmpty): ?string
    {
        $priceRaw = $values['raw']['price'] ?? '';
        if (isset($mapping['price']) && $priceRaw !== '') {
            $normalized = $this->normalizePrice($priceRaw);
            $unsupportedCharacters = preg_replace('/[0-9,.$€£\s-]/u', '', $priceRaw);
            if ($unsupportedCharacters !== '' || $normalized === '' || ! is_numeric($normalized) || (float) $normalized < 0 || (float) $normalized > 99999999.99) {
                return 'Precio inválido';
            }
        }

        $activeRaw = $values['raw']['is_active'] ?? '';
        if (isset($mapping['is_active']) && $activeRaw !== '' && ! in_array(mb_strtolower($activeRaw), $this->booleanValues(), true)) {
            return 'Valor booleano inválido para Activo';
        }

        if (isset($mapping['description']) && mb_strlen((string) ($values['raw']['description'] ?? '')) > 5000) {
            return 'Descripción excede 5000 caracteres';
        }

        foreach ((array) ($mapping['custom'] ?? []) as $key => $column) {
            if (! is_int($column) || ! isset($fields[$key])) {
                continue;
            }

            $field = $fields[$key];
            $raw = $values['raw'][$key] ?? '';
            if ($preserveEmpty && $product !== null && $raw === '') {
                continue;
            }
            if ($raw === '') {
                if ($field->is_required) {
                    return "{$field->label} es requerido";
                }
                continue;
            }

            $value = $this->castValue($raw, $field);
            $rules = ['value' => $field->type->valueRules($field->options)];
            if (($itemRules = $field->type->itemRules($field->options)) !== null) {
                $rules['value.*'] = $itemRules;
            }
            $validator = Validator::make(['value' => $value], $rules);
            if ($validator->fails()) {
                return "{$field->label}: ".$validator->errors()->first();
            }

            if ($field->is_unique) {
                $exists = Product::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->when($product, fn ($query) => $query->where('id', '!=', $product->id))
                    ->whereRaw('custom_data -> ? = ?::jsonb', [$key, json_encode($value)])
                    ->exists();
                if ($exists) {
                    return "{$field->label}: el valor ya existe para otro producto";
                }
            }
        }

        return null;
    }

    /** @param \Illuminate\Support\Collection<string, ProductField> $fields
     * @return array{name:string,price:float|null,description:string|null,is_active:bool,custom:array<string,mixed>,raw:array<string,string>}
     */
    private function rowValues(array $row, array $mapping, $fields): array
    {
        $raw = fn (string $key): string => isset($mapping[$key]) && is_int($mapping[$key]) ? trim((string) ($row[$mapping[$key]] ?? '')) : '';
        $custom = []; $customRaw = [];
        foreach ((array) ($mapping['custom'] ?? []) as $key => $column) {
            if (! is_int($column) || ! isset($fields[$key])) continue;
            $value = trim((string) ($row[$column] ?? ''));
            $customRaw[$key] = $value;
            $custom[$key] = $value === '' ? null : $this->castValue($value, $fields[$key]);
        }
        $price = $raw('price'); $description = $raw('description'); $active = $raw('is_active');
        return [
            'name' => $raw('name'), 'price' => $price === '' ? null : (float) $this->normalizePrice($price),
            'description' => $description === '' ? null : $description,
            'is_active' => $active === '' ? true : in_array(mb_strtolower($active), ['1', 'true', 'si', 'sí', 'yes', 'activo', 'active'], true),
            'custom' => $custom,
            'raw' => ['name' => $raw('name'), 'price' => $price, 'description' => $description, 'is_active' => $active, ...$customRaw],
        ];
    }

    private function updateProduct(Product $product, array $values, array $mapping, bool $preserveEmpty): void
    {
        $payload = [];
        foreach (['name', 'price', 'description', 'is_active'] as $field) {
            if (! isset($mapping[$field]) || ($preserveEmpty && ($values['raw'][$field] ?? '') === '')) continue;
            $payload[$field] = $values[$field];
        }
        $custom = $product->custom_data ?? [];
        foreach ($values['custom'] as $key => $value) {
            if (! ($preserveEmpty && ($values['raw'][$key] ?? '') === '')) $custom[$key] = $value;
        }
        if ($values['custom'] !== []) $payload['custom_data'] = $custom;
        if ($payload !== []) $product->update($payload);
    }

    /** @return array<string, Product> */
    private function existingIndex(int $tenantId, string $matchField): array
    {
        $index = [];
        foreach (Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->get() as $product) {
            $value = $matchField === 'name' ? $product->name : data_get($product->custom_data, Str::after($matchField, 'custom:'));
            if (is_scalar($value) && (string) $value !== '') $index[$this->normalize((string) $value)] = $product;
        }
        return $index;
    }

    /** @return array<string, mixed> */
    private function resolveProposedFields(ProductImport $import): array
    {
        $mapping = $import->mapping; $custom = (array) ($mapping['custom'] ?? []); $created = [];
        foreach ((array) $import->proposed_fields as $proposal) {
            if (! is_array($proposal)) continue;
            $id = (string) ($proposal['id'] ?? ''); $label = trim((string) ($proposal['label'] ?? ''));
            $type = ContactFieldType::tryFrom((string) ($proposal['type'] ?? 'text'));
            if ($id === '' || $label === '' || ! $type) throw ValidationException::withMessages(['mapping' => 'Hay un campo nuevo inválido.']);
            $field = $this->findOrCreateField($import->tenant_id, $label, $type, (array) ($proposal['options'] ?? []), (bool) ($proposal['is_unique'] ?? false));
            foreach ($custom as $key => $column) {
                if ($key === "proposed:{$id}") { unset($custom[$key]); $custom[$field->key] = $column; }
            }
            $created[] = ['key' => $field->key, 'label' => $field->label, 'type' => $field->type->value];
        }
        $mapping['custom'] = $custom; $import->update(['created_fields' => $created]);
        return $mapping;
    }

    private function findOrCreateField(int $tenantId, string $label, ContactFieldType $type, array $options, bool $isUnique): ProductField
    {
        $existing = ProductField::withTrashed()->where('tenant_id', $tenantId)->get()
            ->first(fn (ProductField $field): bool => $this->normalize($field->label) === $this->normalize($label));
        if ($existing && ! $existing->trashed() && $existing->type === $type) return $existing;
        if ($existing) throw ValidationException::withMessages(['mapping' => "El campo '{$label}' ya existe con otro tipo."]);
        if (in_array($type, [ContactFieldType::Select, ContactFieldType::MultiSelect], true) && empty($options['choices'])) {
            throw ValidationException::withMessages(['mapping' => "El campo '{$label}' requiere opciones."]);
        }
        $base = substr(Str::slug($label, '_') ?: 'field', 0, 50); $key = $base; $suffix = 1;
        while (in_array($key, ProductFieldRegistry::reservedKeys(), true) || ProductField::withTrashed()->where('tenant_id', $tenantId)->where('key', $key)->exists()) $key = $base.'_'.++$suffix;
        return ProductField::create(['tenant_id' => $tenantId, 'key' => $key, 'label' => $label, 'type' => $type,
            'options' => $type->requiresOptions() ? $options : null, 'is_required' => false, 'is_unique' => $isUnique,
            'display_order' => (int) ProductField::withoutGlobalScopes()->where('tenant_id', $tenantId)->max('display_order') + 1]);
    }

    /** @return list<array<int, string|null>> */
    private function readRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) throw new \RuntimeException('No se pudo abrir el archivo de importación.');
        $first = fgets($handle); rewind($handle);
        $delimiter = $first !== false && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) if (array_filter($row, fn ($v) => trim((string) $v) !== '') !== []) $rows[] = $row;
        fclose($handle);
        return $rows;
    }

    private function castValue(string $value, ProductField $field): mixed
    {
        return match ($field->type) {
            ContactFieldType::Boolean => in_array(mb_strtolower($value), ['1', 'true', 'si', 'sí', 'yes', 'activo', 'active'], true),
            ContactFieldType::Number, ContactFieldType::Currency => (float) $this->normalizePrice($value),
            ContactFieldType::MultiSelect, ContactFieldType::Repeater => array_values(array_filter(array_map('trim', explode('|', $value)))),
            default => $value,
        };
    }
    /** @return list<string> */
    private function booleanValues(): array { return ['1', '0', 'true', 'false', 'si', 'sí', 'no', 'yes', 'activo', 'inactivo', 'active', 'inactive']; }
    private function normalizePrice(string $value): string { $value = preg_replace('/[^0-9,.-]/', '', $value) ?? ''; return str_contains($value, ',') && str_contains($value, '.') ? str_replace(',', '.', str_replace('.', '', $value)) : str_replace(',', '.', $value); }
    private function normalize(string $value): string { return mb_strtolower(trim($value)); }
}
