<?php

use App\Enums\ContactFieldType;
use App\Models\Contact;
use App\Models\ContactField;
use App\Support\ContactCustomDataNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Normaliza a Y-m-d los valores ya guardados en custom_data para campos de
 * tipo Date, para los contactos cargados antes de que
 * ContactCustomDataNormalizer empezara a normalizar en escritura.
 *
 * No usa Eloquent: un save() por contacto dispararía ContactAutomationObserver
 * y encolaría un job de automatización por cada uno. Sobre una base con miles
 * de contactos eso es un deploy que satura la cola de golpe, sin ningún
 * cambio real de negocio detrás. Se actualiza con UPDATE directo por chunk.
 *
 * Ambigüedades reales ("03/09/2026": ¿3 de septiembre o 9 de marzo?) no son
 * decidibles sin contexto del tenant, así que no se tocan: se loguean para
 * revisión manual y el contacto queda con el valor original. Preferible a
 * adivinar y mover una fecha de vencimiento real.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tenantFields = ContactField::query()
            ->where('type', ContactFieldType::Date->value)
            ->get()
            ->groupBy('tenant_id')
            ->map(fn ($fields) => $fields->pluck('key')->all());

        foreach ($tenantFields as $tenantId => $dateKeys) {
            $this->normalizeTenant((int) $tenantId, $dateKeys);
        }
    }

    /**
     * @param  list<string>  $dateKeys
     */
    private function normalizeTenant(int $tenantId, array $dateKeys): void
    {
        Contact::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('custom_data')
            ->select(['id', 'tenant_id', 'custom_data'])
            ->orderBy('id')
            ->chunkById(500, function ($contacts) use ($dateKeys): void {
                foreach ($contacts as $contact) {
                    $this->normalizeContact($contact, $dateKeys);
                }
            });
    }

    /**
     * @param  list<string>  $dateKeys
     */
    private function normalizeContact(Contact $contact, array $dateKeys): void
    {
        $customData = $contact->custom_data ?? [];
        $changed = false;

        foreach ($dateKeys as $key) {
            if (! array_key_exists($key, $customData) || $customData[$key] === null) {
                continue;
            }

            $raw = $customData[$key];
            $normalized = ContactCustomDataNormalizer::normalizeDate($raw);

            if ($normalized === null) {
                Log::warning('No se pudo normalizar fecha en custom_data durante el backfill', [
                    'contact_id' => $contact->id,
                    'tenant_id' => $contact->tenant_id,
                    'field' => $key,
                    'raw_value' => $raw,
                ]);

                continue;
            }

            if ($normalized !== $raw) {
                $customData[$key] = $normalized;
                $changed = true;
            }
        }

        if ($changed) {
            DB::table('contacts')->where('id', $contact->id)->update([
                'custom_data' => json_encode($customData, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Backfill de datos: no reversible (el formato original de cada valor
        // no se conserva). Sin acción en rollback.
    }
};
