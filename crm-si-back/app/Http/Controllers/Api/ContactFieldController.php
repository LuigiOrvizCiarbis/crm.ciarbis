<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContactFieldType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContactFieldRequest;
use App\Http\Requests\UpdateContactFieldRequest;
use App\Models\ContactField;
use App\Support\ContactFieldRegistry;
use App\Support\ExtractionPresetProvisioner;
use App\Support\ExtractionPresetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContactFieldController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user?->can('contact_fields.view') && ! $user?->can('contact_fields.manage')) {
            abort(403);
        }

        $fields = ContactField::query()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ContactField $f): array => $this->serialize($f))
            ->all();

        return response()->json([
            'data' => $fields,
            'standard' => ContactFieldRegistry::standard(),
        ]);
    }

    public function store(StoreContactFieldRequest $request): JsonResponse
    {
        $type = ContactFieldType::from((string) $request->string('type'));
        $tenantId = $request->user()->tenant_id;
        $key = $this->generateUniqueKey((string) $request->string('label'), $tenantId);

        $field = ContactField::create([
            'tenant_id' => $tenantId,
            'key' => $key,
            'label' => (string) $request->string('label'),
            'type' => $type,
            'options' => $type->requiresOptions() ? $request->input('options') : null,
            'is_required' => (bool) $request->boolean('is_required'),
            'is_unique' => (bool) $request->boolean('is_unique'),
            'display_order' => (int) ($request->input('display_order')
                ?? ((int) ContactField::query()->max('display_order') + 1)),
        ]);

        return response()->json(['data' => $this->serialize($field)], 201);
    }

    public function update(UpdateContactFieldRequest $request, ContactField $contactField): JsonResponse
    {
        $payload = $request->only(['label', 'options', 'is_required', 'is_unique', 'display_order']);

        if (! $contactField->type->requiresOptions()) {
            unset($payload['options']);
        }

        $contactField->update($payload);

        return response()->json(['data' => $this->serialize($contactField->refresh())]);
    }

    public function destroy(Request $request, ContactField $contactField): JsonResponse
    {
        if (! $request->user()?->can('contact_fields.manage')) {
            abort(403);
        }

        $contactField->delete();

        return response()->json(['message' => 'Campo eliminado.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        if (! $request->user()?->can('contact_fields.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.display_order' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['items'] as $row) {
                ContactField::where('id', $row['id'])->update(['display_order' => $row['display_order']]);
            }
        });

        ContactField::clearTenantCache($request->user()->tenant_id);

        return response()->json(['message' => 'Orden actualizado.']);
    }

    /**
     * Aplica una plantilla de campos (por ejemplo los de un contrato de
     * alquiler) para no tener que crearlos de a uno.
     *
     * Los campos quedan como cualquier otro: editables, reordenables y
     * borrables. Reaplicar el preset no duplica ni pisa lo que el tenant
     * cambió; la respuesta dice qué se creó y qué ya estaba.
     */
    public function applyPreset(Request $request, ExtractionPresetProvisioner $provisioner): JsonResponse
    {
        if (! $request->user()?->can('contact_fields.manage')) {
            abort(403);
        }

        $validated = $request->validate([
            'preset' => ['required', 'string', Rule::in(ExtractionPresetRegistry::keys())],
        ]);

        $result = $provisioner->apply($request->user()->tenant_id, $validated['preset']);

        $fields = ContactField::query()
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (ContactField $f): array => $this->serialize($f))
            ->all();

        return response()->json([
            'data' => $fields,
            'created' => $result['created'],
            'existing' => $result['existing'],
        ]);
    }

    private function generateUniqueKey(string $label, int $tenantId): string
    {
        $base = Str::slug($label, '_');
        if ($base === '') {
            $base = 'field';
        }
        $base = substr($base, 0, 60);

        $reserved = ContactFieldRegistry::reservedKeys();
        $candidate = $base;
        $suffix = 1;

        while (
            in_array($candidate, $reserved, true)
            || ContactField::withTrashed()
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
     * @return array<string, mixed>
     */
    private function serialize(ContactField $field): array
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
