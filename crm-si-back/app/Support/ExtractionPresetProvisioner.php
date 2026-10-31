<?php

namespace App\Support;

use App\Models\ContactField;
use Illuminate\Support\Facades\DB;

class ExtractionPresetProvisioner
{
    /**
     * Crea los campos de un preset para un tenant.
     *
     * Idempotente: una key que ya existe se reusa tal cual, sin duplicarla con
     * sufijo ni pisar lo que el tenant haya editado. También se saltean las
     * borradas (soft delete): si alguien quitó "garante" a propósito, volver a
     * aplicar el preset no debería resucitarlo.
     *
     * Transaccional: aplicar medio preset dejaría al tenant con un formulario
     * incompleto y sin señal de qué falló.
     *
     * @return array{created: list<string>, existing: list<string>}
     */
    public function apply(int $tenantId, string $preset): array
    {
        $definition = ExtractionPresetRegistry::find($preset);

        if ($definition === null) {
            throw new \InvalidArgumentException("Preset desconocido: {$preset}");
        }

        return DB::transaction(function () use ($tenantId, $definition): array {
            $created = [];
            $existing = [];

            // Los nuevos van después de lo que el tenant ya tenga.
            $order = (int) ContactField::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->max('display_order');

            foreach ($definition['fields'] as $field) {
                $alreadyThere = ContactField::withoutGlobalScopes()
                    ->withTrashed()
                    ->where('tenant_id', $tenantId)
                    ->where('key', $field['key'])
                    ->exists();

                if ($alreadyThere) {
                    $existing[] = $field['key'];

                    continue;
                }

                ContactField::create([
                    'tenant_id' => $tenantId,
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'options' => $field['options'] ?? null,
                    'is_required' => false,
                    'is_unique' => false,
                    'display_order' => ++$order,
                ]);

                $created[] = $field['key'];
            }

            ContactField::clearTenantCache($tenantId);

            return ['created' => $created, 'existing' => $existing];
        });
    }
}
