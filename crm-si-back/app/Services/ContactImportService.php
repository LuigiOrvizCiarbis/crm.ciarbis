<?php

namespace App\Services;

use App\Enums\ContactFieldType;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ProductImport;
use Illuminate\Http\UploadedFile;
use App\Support\ContactCustomDataNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactImportService
{
    /** @return array<string, mixed> */
    public function preview(UploadedFile $file, array $mapping): array
    {
        $rows = $this->readImportRows($file->getRealPath());
        $dataRows = array_slice($rows, 1);
        $nameColumn = $mapping['name'] ?? null;
        if (! is_int($nameColumn)) {
            throw ValidationException::withMessages(['mapping' => 'Debes mapear la columna Nombre.']);
        }
        $seen = [];
        $duplicates = [];
        foreach ($dataRows as $offset => $row) {
            $value = strtolower(trim((string) ($row[$nameColumn] ?? '')));
            if ($value === '') continue;
            if (isset($seen[$value])) $duplicates[] = $offset + 2;
            $seen[$value] = true;
        }
        return [
            'total_rows' => count($dataRows),
            'sample_rows' => array_slice($dataRows, 0, 5),
            'duplicate_rows' => array_slice($duplicates, 0, 50),
            'proposed_fields' => count((array) ($mapping['proposed_fields'] ?? [])),
            'warnings' => $duplicates === [] ? [] : ['Hay nombres repetidos en el archivo.'],
        ];
    }

    /** @return array<string, mixed> */
    public function runQueued(ProductImport $import): array
    {
        $mapping = $this->resolveProposedFields($import);
        $path = Storage::disk('local')->path($import->file_path);
        $file = new UploadedFile($path, basename($path), 'text/csv', null, true);
        return $this->import($file, $mapping, $import->tenant_id);
    }

    /** @return array<string, mixed> */
    private function resolveProposedFields(ProductImport $import): array
    {
        $mapping = $import->mapping;
        foreach ((array) $import->proposed_fields as $proposal) {
            if (! is_array($proposal)) continue;
            $label = trim((string) ($proposal['label'] ?? ''));
            $type = ContactFieldType::tryFrom((string) ($proposal['type'] ?? 'text'));
            $id = (string) ($proposal['id'] ?? '');
            if ($label === '' || $id === '' || ! $type) throw ValidationException::withMessages(['mapping' => 'Hay un campo nuevo inválido.']);
            $field = $this->findOrCreateField($import->tenant_id, $label, $type, (array) ($proposal['options'] ?? []));
            foreach ((array) ($mapping['custom'] ?? []) as $key => $column) {
                if ($key === "proposed:{$id}") {
                    unset($mapping['custom'][$key]);
                    $mapping['custom'][$field->key] = $column;
                }
            }
        }
        return $mapping;
    }

    private function findOrCreateField(int $tenantId, string $label, ContactFieldType $type, array $options): ContactField
    {
        $existing = ContactField::withTrashed()->where('tenant_id', $tenantId)->get()->first(fn (ContactField $field): bool => mb_strtolower(trim($field->label)) === mb_strtolower(trim($label)));
        if ($existing && ! $existing->trashed() && $existing->type === $type) return $existing;
        if ($existing) throw ValidationException::withMessages(['mapping' => "El campo '{$label}' ya existe con otro tipo."]);
        if (in_array($type, [ContactFieldType::Select, ContactFieldType::MultiSelect], true) && empty($options['choices'])) {
            throw ValidationException::withMessages(['mapping' => "El campo '{$label}' requiere opciones."]);
        }
        $base = substr(Str::slug($label, '_') ?: 'field', 0, 50);
        $key = $base;
        $suffix = 1;
        while (ContactField::withTrashed()->where('tenant_id', $tenantId)->where('key', $key)->exists()) $key = $base.'_'.++$suffix;
        return ContactField::create(['tenant_id' => $tenantId, 'key' => $key, 'label' => $label, 'type' => $type, 'options' => $type->requiresOptions() ? $options : null, 'is_required' => false, 'is_unique' => false, 'display_order' => (int) ContactField::where('tenant_id', $tenantId)->max('display_order') + 1]);
    }

    /** @return list<array<int, string|null>> */
    private function readImportRows(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) throw new \RuntimeException('No se pudo abrir el archivo.');
        $first = fgets($handle); rewind($handle);
        $delimiter = $first !== false && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) if (array_filter($row, fn ($v) => trim((string) $v) !== '') !== []) $rows[] = $row;
        fclose($handle);
        return $rows;
    }

    /**
     * Import contacts from a CSV file.
     *
     * @param  array<string, mixed>  $mapping  Column mapping. Keys: name, phone, email.
     *                                         Optionally `custom` => [fieldKey => columnIndex].
     * @return array{imported: int, duplicates: int, errors: int, error_rows: list<array{row: int, reason: string}>, total: int}
     */
    public function import(UploadedFile $file, array $mapping, int $tenantId): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return ['imported' => 0, 'duplicates' => 0, 'errors' => 0, 'error_rows' => [], 'total' => 0];
        }

        $delimiter = $this->detectDelimiter($handle);

        if (($mapping['has_headers'] ?? true) !== false) {
            fgetcsv($handle, 0, $delimiter);
        }

        $existingPhones = [];
        $existingEmails = [];

        Contact::where('tenant_id', $tenantId)
            ->select('phone', 'email')
            ->each(function (Contact $c) use (&$existingPhones, &$existingEmails) {
                if ($c->phone !== null && $c->phone !== '') {
                    $existingPhones[$this->normalizePhone($c->phone)] = true;
                }
                if ($c->email !== null && $c->email !== '') {
                    $existingEmails[strtolower(trim($c->email))] = true;
                }
            });

        $customMapping = is_array($mapping['custom'] ?? null) ? $mapping['custom'] : [];
        $contactFields = ContactField::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('key');

        $uniqueFieldKeys = $contactFields
            ->filter(fn (ContactField $f): bool => (bool) $f->is_unique)
            ->keys()
            ->all();

        $existingUniqueValues = [];
        if ($uniqueFieldKeys !== []) {
            foreach ($uniqueFieldKeys as $key) {
                $existingUniqueValues[$key] = [];
            }
            Contact::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('custom_data')
                ->select('custom_data')
                ->each(function (Contact $c) use (&$existingUniqueValues, $uniqueFieldKeys): void {
                    $data = $c->custom_data ?? [];
                    foreach ($uniqueFieldKeys as $key) {
                        if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                            continue;
                        }
                        $existingUniqueValues[$key][$this->uniqueHash($data[$key])] = true;
                    }
                });
        }

        $imported = 0;
        $duplicates = 0;
        $errors = 0;
        $errorRows = [];
        $batch = [];
        $rowNumber = 1;

        $now = now();
        $nameCol = $mapping['name'];
        $phoneCol = $mapping['phone'] ?? null;
        $emailCol = $mapping['email'] ?? null;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowNumber++;

            $name = trim($row[$nameCol] ?? '');
            $phone = $phoneCol !== null ? trim($row[$phoneCol] ?? '') : '';
            $email = $emailCol !== null ? trim($row[$emailCol] ?? '') : '';

            if ($name === '') {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => 'Nombre vacío'];

                continue;
            }

            if (mb_strlen($name) > 255) {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => 'Nombre excede 255 caracteres'];

                continue;
            }

            if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => 'Email inválido'];

                continue;
            }

            if (mb_strlen($phone) > 50) {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => 'Teléfono excede 50 caracteres'];

                continue;
            }

            $normalizedPhone = $this->normalizePhone($phone);
            $normalizedEmail = strtolower($email);

            if (($normalizedPhone !== '' && isset($existingPhones[$normalizedPhone]))
                || ($normalizedEmail !== '' && isset($existingEmails[$normalizedEmail]))) {
                $duplicates++;

                continue;
            }

            $customResult = $this->extractCustomData($row, $customMapping, $contactFields);
            if ($customResult['error'] !== null) {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => $customResult['error']];

                continue;
            }

            $uniqueError = $this->checkUniqueCollisions($customResult['data'], $uniqueFieldKeys, $existingUniqueValues, $contactFields);
            if ($uniqueError !== null) {
                $errors++;
                $errorRows[] = ['row' => $rowNumber, 'reason' => $uniqueError];

                continue;
            }

            foreach ($uniqueFieldKeys as $key) {
                if (array_key_exists($key, $customResult['data']) && $customResult['data'][$key] !== null && $customResult['data'][$key] !== '') {
                    $existingUniqueValues[$key][$this->uniqueHash($customResult['data'][$key])] = true;
                }
            }

            if ($normalizedPhone !== '') {
                $existingPhones[$normalizedPhone] = true;
            }
            if ($normalizedEmail !== '') {
                $existingEmails[$normalizedEmail] = true;
            }

            $batch[] = [
                'tenant_id' => $tenantId,
                'name' => $name,
                'phone' => $phone ?: null,
                'email' => $email ?: null,
                'source' => 'manual',
                'custom_data' => json_encode($customResult['data'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 100) {
                Contact::insert($batch);
                $imported += count($batch);
                $batch = [];
            }
        }

        if (count($batch) > 0) {
            Contact::insert($batch);
            $imported += count($batch);
        }

        fclose($handle);

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'errors' => $errors,
            'error_rows' => array_slice($errorRows, 0, 50),
            'total' => $imported + $duplicates + $errors,
        ];
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int>  $customMapping
     * @param  Collection<string, ContactField>  $fields
     * @return array{data: array<string, mixed>, error: ?string}
     */
    private function extractCustomData(array $row, array $customMapping, $fields): array
    {
        $data = [];

        foreach ($fields as $key => $field) {
            $columnIndex = $customMapping[$key] ?? null;
            $raw = $columnIndex !== null && isset($row[$columnIndex]) ? trim((string) $row[$columnIndex]) : '';

            if ($raw === '') {
                if ($field->is_required) {
                    return ['data' => $data, 'error' => "Campo requerido vacío: {$field->label}"];
                }

                continue;
            }

            $value = $this->castRawValue($raw, $field);

            $rules = ['value' => $field->type->valueRules($field->options)];
            $itemRules = $field->type->itemRules($field->options);
            if ($itemRules !== null) {
                $rules['value.*'] = $itemRules;
            }

            $validator = Validator::make(['value' => $value], $rules);
            if ($validator->fails()) {
                return ['data' => $data, 'error' => "Valor inválido para {$field->label}"];
            }

            $data[$key] = $value;
        }

        return ['data' => $data, 'error' => null];
    }

    /**
     * @param  array<string, mixed>  $customData
     * @param  list<string>  $uniqueFieldKeys
     * @param  array<string, array<string, bool>>  $existingUniqueValues
     * @param  Collection<string, ContactField>  $fields
     */
    private function checkUniqueCollisions(array $customData, array $uniqueFieldKeys, array $existingUniqueValues, $fields): ?string
    {
        foreach ($uniqueFieldKeys as $key) {
            if (! array_key_exists($key, $customData)) {
                continue;
            }
            $value = $customData[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $hash = $this->uniqueHash($value);
            if (isset($existingUniqueValues[$key][$hash])) {
                $label = $fields->get($key)?->label ?? $key;

                return "Valor duplicado para campo único: {$label}";
            }
        }

        return null;
    }

    private function uniqueHash(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function castRawValue(string $raw, ContactField $field): mixed
    {
        return match ($field->type->value) {
            'repeater' => $this->castRepeater($raw),
            'multi_select' => array_values(array_filter(array_map('trim', preg_split('/[;|]/', $raw)))),
            'boolean' => in_array(strtolower($raw), ['1', 'true', 'yes', 'si', 'sí'], true),
            'number' => is_numeric($raw) ? $raw + 0 : $raw,
            'currency' => $this->castCurrency($raw),
            'date' => ContactCustomDataNormalizer::normalizeDate($raw) ?? $raw,
            default => $raw,
        };
    }

    /**
     * Un CSV real trae importes como se los ve en pantalla: "$ 1.250.000,50",
     * "USD 1,250,000.50". Se limpia el símbolo y los separadores de miles antes
     * de convertir; si aun así no es un número, se devuelve el crudo para que
     * la validación lo rechace con el mensaje del campo.
     */
    private function castCurrency(string $raw): mixed
    {
        $cleaned = preg_replace('/[^\d,.\-]/', '', $raw) ?? '';
        if ($cleaned === '') {
            return $raw;
        }

        $lastComma = strrpos($cleaned, ',');
        $lastDot = strrpos($cleaned, '.');
        if ($lastComma !== false && $lastDot !== false) {
            // Gana el separador más a la derecha: es el decimal.
            $cleaned = $lastComma > $lastDot
                ? str_replace(',', '.', str_replace('.', '', $cleaned))
                : str_replace(',', '', $cleaned);
        } elseif ($lastComma !== false) {
            // Una sola coma: decimal si deja 1-2 dígitos ("1250,5"), separador
            // de miles si deja exactamente 3 ("1,250").
            $decimals = strlen($cleaned) - $lastComma - 1;
            $cleaned = $decimals === 3
                ? str_replace(',', '', $cleaned)
                : str_replace(',', '.', $cleaned);
        } elseif ($lastDot !== false) {
            // Mismo criterio que la coma, por simetría: "1.250" son mil
            // doscientos cincuenta, no uno con veinticinco. Un importe con
            // decimales reales se escribe "1250.50", que deja 2 dígitos.
            $decimals = strlen($cleaned) - $lastDot - 1;
            if (substr_count($cleaned, '.') > 1 || $decimals === 3) {
                $cleaned = str_replace('.', '', $cleaned);
            }
        }

        return is_numeric($cleaned) ? $cleaned + 0 : $raw;
    }

    private function castRepeater(string $raw): mixed
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $raw;
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

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', $phone) ?: '';
    }
}
