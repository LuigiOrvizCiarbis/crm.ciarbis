<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\WebhookEndpoint;
use App\Rules\ValidContactCustomData;
use App\Support\ContactCustomDataNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

/**
 * Upsert idempotente de contactos recibidos por un webhook entrante.
 *
 * Corre SIN usuario autenticado, así que:
 * - tenant_id se pasa siempre explícito (el TenantScope y el auto-assign de
 *   BelongsToTenant no corren sin Auth).
 * - la validación de custom_data recibe el tenant explícito.
 *
 * La lógica vive en un service (no en el controller) para poder migrarla a un
 * Job en el futuro sin tocar el endpoint.
 */
class WebhookContactUpsertService
{
    /**
     * @param  list<array<string, mixed>>  $items  Items ya validados estructuralmente por el controller.
     * @return array{created: int, updated: int, failed: int, errors: list<array{index: int, external_id: mixed, message: string}>}
     */
    public function process(WebhookEndpoint $endpoint, array $items): array
    {
        $tenantId = $endpoint->tenant_id;

        // Se cargan los campos custom del tenant una sola vez por request.
        $knownKeys = ContactField::forTenant($tenantId)->pluck('key')->all();

        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($items as $index => $item) {
            $externalId = $item['external_id'];
            $customData = is_array($item['custom_data'] ?? null) ? $item['custom_data'] : [];
            $customData = ContactCustomDataNormalizer::normalize($customData, $tenantId);

            $unknownKeys = array_diff(array_keys($customData), $knownKeys);
            if ($unknownKeys !== []) {
                $failed++;
                $errors[] = [
                    'index' => $index,
                    'external_id' => $externalId,
                    'message' => 'Campos custom desconocidos: '.implode(', ', $unknownKeys),
                ];

                continue;
            }

            $existing = Contact::query()
                ->where('tenant_id', $tenantId)
                ->where('source', 'webhook')
                ->where('external_id', $externalId)
                ->first();

            if ($customData !== []) {
                $validator = Validator::make(
                    ['custom_data' => $customData],
                    ['custom_data' => ['array', new ValidContactCustomData($existing?->id, array_keys($customData), $tenantId)]],
                );

                if ($validator->fails()) {
                    $failed++;
                    $errors[] = [
                        'index' => $index,
                        'external_id' => $externalId,
                        'message' => $validator->errors()->first(),
                    ];

                    continue;
                }
            }

            try {
                if ($existing) {
                    $this->applyUpdate($existing, $item, $customData);
                    $updated++;
                } else {
                    $this->createContact($tenantId, $externalId, $item, $customData);
                    $created++;
                }
            } catch (QueryException $e) {
                // Carrera contra el índice único parcial (tenant_id, source, external_id):
                // otro request creó el mismo contacto entre el lookup y el insert.
                // Re-buscamos y actualizamos una vez.
                $retry = Contact::query()
                    ->where('tenant_id', $tenantId)
                    ->where('source', 'webhook')
                    ->where('external_id', $externalId)
                    ->first();

                if ($retry) {
                    $this->applyUpdate($retry, $item, $customData);
                    $updated++;
                } else {
                    $failed++;
                    $errors[] = [
                        'index' => $index,
                        'external_id' => $externalId,
                        'message' => 'No se pudo guardar el contacto.',
                    ];
                }
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $customData
     */
    private function createContact(int $tenantId, mixed $externalId, array $item, array $customData): void
    {
        Contact::create([
            'tenant_id' => $tenantId,
            'source' => 'webhook',
            'external_id' => $externalId,
            'name' => $item['name'],
            'phone' => ($item['phone'] ?? null) ?: null,
            'email' => ($item['email'] ?? null) ?: null,
            'custom_data' => $customData,
        ]);
    }

    /**
     * Update parcial: merge del custom_data (no pisa keys no enviadas) y solo los
     * campos escalares presentes en el item.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $customData
     */
    private function applyUpdate(Contact $contact, array $item, array $customData): void
    {
        $contact->name = $item['name'];

        if (array_key_exists('phone', $item)) {
            $contact->phone = ($item['phone'] ?? null) ?: null;
        }

        if (array_key_exists('email', $item)) {
            $contact->email = ($item['email'] ?? null) ?: null;
        }

        if ($customData !== []) {
            $contact->custom_data = array_merge($contact->custom_data ?? [], $customData);
        }

        $contact->save();
    }
}
