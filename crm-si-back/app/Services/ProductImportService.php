<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductField;
use App\Rules\ValidProductCustomData;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProductImportService
{
    /**
     * Import products from a CSV file.
     *
     * @param  array<string, mixed>  $mapping  Column mapping. Keys: name (required), price, description, is_active.
     * @return array{imported: int, duplicates: int, errors: int, error_rows: list<array{row: int, reason: string}>, total: int}
     */
    public function import(UploadedFile $file, array $mapping, int $tenantId, int $userId): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['imported' => 0, 'duplicates' => 0, 'errors' => 0, 'error_rows' => [], 'total' => 0];
        }

        try {
            return DB::transaction(function () use ($handle, $mapping, $tenantId, $userId): array {
                return $this->processRows($handle, $mapping, $tenantId, $userId);
            });
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parsea las filas del CSV e inserta en lotes. Corre dentro de una transacción:
     * si algún insert falla, se revierte todo el import.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $mapping
     * @return array{imported: int, duplicates: int, errors: int, error_rows: list<array{row: int, reason: string}>, total: int}
     */
    private function processRows($handle, array $mapping, int $tenantId, int $userId): array
    {
        $delimiter = $this->detectDelimiter($handle);

        $hasHeaders = ($mapping['has_headers'] ?? true) !== false;
        if ($hasHeaders) {
            fgetcsv($handle, 0, $delimiter);
        }

        $existingNames = [];
        Product::where('tenant_id', $tenantId)
            ->select('name')
            ->each(function (Product $p) use (&$existingNames): void {
                if ($p->name !== null && $p->name !== '') {
                    $existingNames[$this->normalizeName($p->name)] = true;
                }
            });

        $imported = 0;
        $duplicates = 0;
        $errors = 0;
        $errorRows = [];
        $batch = [];
        $rowNumber = 1;

        $now = now();
        $nameCol = $mapping['name'];
        $priceCol = $mapping['price'] ?? null;
        $descCol = $mapping['description'] ?? null;
        $activeCol = $mapping['is_active'] ?? null;
        $customMapping = is_array($mapping['custom'] ?? null) ? $mapping['custom'] : [];
        $customFields = ProductField::forTenant($tenantId)->keyBy('key');

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            $name = trim($row[$nameCol] ?? '');
            $price = $priceCol !== null ? trim($row[$priceCol] ?? '') : '';
            $description = $descCol !== null ? trim($row[$descCol] ?? '') : '';
            $active = $activeCol !== null ? trim($row[$activeCol] ?? '') : '';
            $customData = [];
            foreach ($customMapping as $key => $column) {
                if (! $customFields->has($key)) continue;
                $raw = trim((string) ($row[$column] ?? ''));
                if ($raw === '') {
                    $customData[$key] = null;
                    continue;
                }
                if ($customFields[$key]->type->value === 'boolean' && ! in_array(mb_strtolower($raw), ['1', '0', 'true', 'false', 'si', 'sí', 'no', 'yes', 'activo', 'inactivo'], true)) {
                    $errors++;
                    $errorRows[] = ['row' => $rowNumber, 'reason' => "Valor booleano inválido para {$customFields[$key]->label}"];
                    continue 2;
                }
                $customData[$key] = $this->castCustomValue($raw, $customFields[$key]);
            }

            if ($customData !== []) {
                $customValidator = Validator::make(
                    ['custom_data' => $customData],
                    ['custom_data' => [new ValidProductCustomData(null, array_keys($customData))]],
                );
                if ($customValidator->fails()) {
                    $errors++;
                    $errorRows[] = ['row' => $rowNumber, 'reason' => $customValidator->errors()->first()];
                    continue;
                }
            }

            if ($name === '') {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => 'Nombre vacío'];

                continue;
            }

            if (mb_strlen($name) > 150) {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => 'Nombre excede 150 caracteres'];

                continue;
            }

            $priceValue = null;
            if ($price !== '') {
                $normalizedPrice = $this->normalizePrice($price);
                if (! is_numeric($normalizedPrice) || (float) $normalizedPrice < 0) {
                    $errors++;
                    $errorRows[] = ['row' => $rowNumber, 'reason' => 'Precio inválido'];

                    continue;
                }
                $priceValue = round((float) $normalizedPrice, 2);
                if ($priceValue > 99999999.99) {
                    $errors++;
                    $errorRows[] = ['row' => $rowNumber, 'reason' => 'Precio excede el máximo'];

                    continue;
                }
            }

            if (mb_strlen($description) > 5000) {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => 'Descripción excede 5000 caracteres'];

                continue;
            }

            $normalizedName = $this->normalizeName($name);
            if (isset($existingNames[$normalizedName])) {
                $duplicates++;

                continue;
            }

            $existingNames[$normalizedName] = true;

            $batch[] = [
                'tenant_id' => $tenantId,
                'created_by' => $userId,
                'name' => $name,
                'price' => $priceValue,
                'description' => $description ?: null,
                'is_active' => $activeCol !== null ? $this->parseBool($active) : true,
                'custom_data' => $customData,
                'source' => 'import',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 100) {
                Product::insert($batch);
                $imported += count($batch);
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            Product::insert($batch);
            $imported += count($batch);
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'error_rows' => array_slice($errorRows, 0, 50),
            'total' => $imported + $duplicates + $errors,
        ];
    }

    private function parseBool(string $raw): bool
    {
        if ($raw === '') {
            return true;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'si', 'sí', 'activo', 'active'], true);
    }

    private function normalizePrice(string $value): string
    {
        $value = preg_replace('/[^0-9,.-]/', '', trim($value)) ?? '';
        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
            return str_replace(',', '.', $value);
        }
        if (str_contains($value, ',')) return str_replace(',', '.', $value);
        return $value;
    }

    private function castCustomValue(string $raw, ProductField $field): mixed
    {
        if ($field->type->value === 'boolean') {
            return in_array(mb_strtolower($raw), ['1', 'true', 'si', 'sí', 'yes', 'activo'], true);
        }
        if (in_array($field->type->value, ['number', 'currency'], true)) {
            return (float) $this->normalizePrice($raw);
        }
        if (in_array($field->type->value, ['multi_select', 'repeater'], true)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : array_values(array_filter(array_map('trim', explode('|', $raw))));
        }
        return $raw;
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @param  resource  $handle
     */
    private function detectDelimiter($handle): string
    {
        $firstLine = fgets($handle);
        rewind($handle);

        if ($firstLine === false) {
            return ',';
        }

        $semicolons = substr_count($firstLine, ';');
        $commas = substr_count($firstLine, ',');

        return $semicolons > $commas ? ';' : ',';
    }
}
