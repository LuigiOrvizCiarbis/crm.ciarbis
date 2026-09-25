<?php

namespace App\Http\Controllers\Api;

use App\Enums\ContactFieldType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ImportContactsRequest;
use App\Http\Resources\ContactResource;
use App\Models\BillingConfig;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\User;
use App\Rules\ValidContactCustomData;
use App\Services\ContactImportService;
use App\Support\BranchRuleResolver;
use App\Support\ContactCustomDataNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Contact::class);

        $user = $request->user();
        $search = trim($request->query('search', ''));

        $q = Contact::query()
            ->visibleTo($user)
            ->with([
                'tags',
                'conversations' => fn ($c) => $c->latest('last_message_at')->limit(1),
            ]);

        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->whereLike('name', "%$search%", caseSensitive: false)
                    ->orWhereLike('phone', "%$search%", caseSensitive: false);
            });
        }

        if ($request->filled('source')) {
            $q->where('source', $request->query('source'));
        }

        if ($request->filled('branch_id') && ($user->isTenantOwner() || $user->can('branches.view_all'))) {
            $q->where('branch_id', (int) $request->query('branch_id'));
        }

        if ($request->filled('tags')) {
            $q->withTagSlugs($this->parseTagSlugs((string) $request->query('tags')));
        }

        $customFilter = $request->query('custom');
        if (is_array($customFilter) && $customFilter !== []) {
            $allowedKeys = ContactField::forCurrentTenant()->pluck('key')->all();
            foreach ($customFilter as $key => $value) {
                if (! is_string($key) || ! in_array($key, $allowedKeys, true) || $value === null || $value === '') {
                    continue;
                }
                $q->whereCustomField($key, $value);
            }
        }

        $customRangeFilter = $request->query('custom_range');
        if (is_array($customRangeFilter) && $customRangeFilter !== []) {
            $fieldsByKey = ContactField::forCurrentTenant()->keyBy('key');
            foreach ($customRangeFilter as $key => $range) {
                if (! is_string($key) || ! is_array($range) || ! $fieldsByKey->has($key)) {
                    continue;
                }
                $field = $fieldsByKey->get($key);
                if (! in_array($field->type, [ContactFieldType::Date, ContactFieldType::Number], true)) {
                    continue;
                }
                $from = isset($range['from']) && $range['from'] !== '' ? (string) $range['from'] : null;
                $to = isset($range['to']) && $range['to'] !== '' ? (string) $range['to'] : null;
                if ($from === null && $to === null) {
                    continue;
                }
                $q->whereCustomFieldRange($key, $field->type, $from, $to);
            }
        }

        // Un contacto cuenta como cliente con ciclo de cobranza activo cuando
        // tiene el campo de estado cargado con alguno de los valores que el
        // motor entiende (BillingConfig::STATUSES). No hay flag ni tabla
        // aparte: la "calificación" ocurre al cargarle ese campo, sea a mano
        // o por webhook. Sin config habilitada el filtro no aplica — el front
        // tampoco ofrece el control en ese caso.
        if ($request->query('billing') === 'clients') {
            $billingConfig = BillingConfig::where('tenant_id', $user->tenant_id)
                ->where('enabled', true)
                ->first();

            if ($billingConfig) {
                $q->where(function ($w) use ($billingConfig) {
                    foreach (BillingConfig::STATUSES as $status) {
                        $w->orWhereRaw('custom_data ->> ? = ?', [$billingConfig->status_field_key, $status]);
                    }
                });
            }
        }

        $sortBy = (string) $request->query('sort_by', 'updated_at');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortableColumns = ['name', 'phone', 'email', 'source', 'created_at', 'updated_at'];

        // Las columnas custom llegan como `custom:<key>`. La key se valida
        // contra los campos del tenant antes de tocar el SQL: nunca se
        // interpola, pero una key inexistente ordenaría por un JSON vacío y
        // devolvería el listado en orden arbitrario en vez de fallar visible.
        $customSortKey = str_starts_with($sortBy, 'custom:') ? substr($sortBy, 7) : null;
        $customSortField = $customSortKey !== null
            ? ContactField::forCurrentTenant()->firstWhere('key', $customSortKey)
            : null;

        if ($customSortField !== null) {
            $q->orderByCustomField($customSortField->key, $customSortField->type, $sortDir);
        } else {
            if (! in_array($sortBy, $sortableColumns, true)) {
                // Se descarta también la dirección pedida: era para otra columna,
                // y conservarla dejaba el fallback en `updated_at asc`, o sea los
                // contactos más viejos primero.
                $sortBy = 'updated_at';
                $sortDir = 'desc';
            }
            $q->orderBy($sortBy, $sortDir);
        }

        $contacts = $q->paginate(
            (int) $request->query('per_page', 20)
        );

        return response()->json([
            'data' => ContactResource::collection($contacts->getCollection()),
            'meta' => [
                'total' => $contacts->total(),
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'per_page' => $contacts->perPage(),
                'from' => $contacts->firstItem(),
                'to' => $contacts->lastItem(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Contact::class);

        $user = $request->user();
        $monthAgo = now()->subDays(30);

        $base = fn () => Contact::query()->visibleTo($user);

        $total = $base()->count();
        $newThisMonth = $base()
            ->where('created_at', '>=', $monthAgo)
            ->count();

        $activeLeads = $base()
            ->whereHas('conversations', fn ($q) => $q->where('status', 'open'))
            ->count();

        $qualified = $base()
            ->whereHas('opportunities', fn ($q) => $q->whereIn('status', ['won', 'open'])->whereNotNull('pipeline_stage_id'))
            ->count();

        $won = $base()
            ->whereHas('opportunities', fn ($q) => $q->where('status', 'won'))
            ->count();

        $conversionRate = $total > 0 ? round(($won / $total) * 100, 1) : 0.0;

        return response()->json([
            'total_contacts' => $total,
            'new_this_month' => $newThisMonth,
            'active_leads' => $activeLeads,
            'qualified' => $qualified,
            'won' => $won,
            'conversion_rate' => $conversionRate,
            ...$this->billingSummary($user),
        ]);
    }

    /**
     * Buckets de cobranzas (al_dia / por_vencer / vencido) para las tarjetas
     * de /contactos. Si el tenant no tiene billing_configs o está
     * deshabilitado, no se emite ninguna clave: el front no muestra las
     * tarjetas en vez de mostrarlas en 0 (que confundiría "no configurado"
     * con "todo al día").
     *
     * "Por vencer" es una ventana fija de 7 días — mismo horizonte que el
     * ejemplo de filtro de la Fase 1 ("vence esta semana"). El plan no
     * define ese número para los KPIs; se ancla al único precedente que da.
     *
     * @return array<string, int>
     */
    private function billingSummary(User $user): array
    {
        $config = BillingConfig::where('tenant_id', $user->tenant_id)
            ->where('enabled', true)
            ->first();

        if (! $config) {
            return [];
        }

        $today = now($config->timezone)->format('Y-m-d');
        $weekOut = now($config->timezone)->addDays(7)->format('Y-m-d');

        $base = fn () => Contact::query()->visibleTo($user);

        $alDia = $base()
            ->whereCustomField($config->status_field_key, 'al_dia')
            ->count();

        $unpaidStatuses = fn ($q) => $q->where(function ($w) use ($config) {
            $w->whereRaw('custom_data ->> ? = ?', [$config->status_field_key, 'impago'])
                ->orWhereRaw('custom_data ->> ? = ?', [$config->status_field_key, 'en_prueba']);
        });

        $porVencer = $base()
            ->tap($unpaidStatuses)
            ->whereCustomFieldRange($config->due_date_field_key, ContactFieldType::Date, $today, $weekOut)
            ->count();

        $vencido = $base()
            ->tap($unpaidStatuses)
            ->whereCustomFieldRange($config->due_date_field_key, ContactFieldType::Date, null, $today)
            // El corte de "por vencer" ya cuenta hoy, así que vencido excluye
            // hoy para no duplicar el contacto en las dos tarjetas.
            ->whereRaw('custom_data ->> ? != ?', [$config->due_date_field_key, $today])
            ->count();

        return [
            'billing_al_dia' => $alDia,
            'billing_por_vencer' => $porVencer,
            'billing_vencido' => $vencido,
        ];
    }

    public function store(Request $request)
    {
        $this->authorize('create', Contact::class);
        $tenantId = $request->user()->tenant_id;
        $customData = ContactCustomDataNormalizer::normalize((array) $request->input('custom_data', []), $tenantId);
        $request->merge(['custom_data' => $customData]);
        $validated = $request->validate($this->contactRules());

        $contact = Contact::create([
            'tenant_id' => $request->user()->tenant_id,
            ...$validated,
            'source' => $validated['source'] ?? 'manual',
            'custom_data' => $validated['custom_data'] ?? [],
        ]);

        $contact->load('tags');

        return response()->json(['data' => new ContactResource($contact)], 201);
    }

    public function show(Request $request, Contact $contact)
    {
        $this->authorize('view', $contact);

        $contact->load([
            'tags',
            'conversations' => fn ($c) => $c->latest('last_message_at')
                ->with(['pipelineStage:id,name', 'assignedUser:id,name,email'])
                ->limit(5),
        ]);

        return response()->json(['data' => new ContactResource($contact)]);
    }

    public function update(Request $request, Contact $contact)
    {
        $this->authorize('update', $contact);

        $providedCustomKeys = [];
        if ($request->has('custom_data')) {
            $incoming = ContactCustomDataNormalizer::normalize((array) $request->input('custom_data'), $contact->tenant_id);
            $providedCustomKeys = array_keys($incoming);
            $merged = array_merge($contact->custom_data ?? [], $incoming);
            $request->merge(['custom_data' => $merged]);
        } else {
            $request->merge(['custom_data' => $contact->custom_data ?? []]);
        }

        $validated = $request->validate($this->contactRules(
            partial: true,
            contactId: $contact->id,
            providedCustomKeys: $providedCustomKeys,
        ));

        $contact->update($validated);
        $contact->load('tags');

        return response()->json(['data' => new ContactResource($contact)]);
    }

    public function destroy(Request $request, Contact $contact)
    {
        $this->authorize('delete', $contact);

        $contact->delete();

        return response()->json(['message' => 'Contacto eliminado']);
    }

    public function import(ImportContactsRequest $request): JsonResponse
    {
        $this->authorize('import', Contact::class);
        $mapping = $request->decodedMapping();
        $tenantId = $request->user()->tenant_id;

        $service = new ContactImportService;
        $result = $service->import($request->file('file'), $mapping, $tenantId);

        return response()->json(['data' => $result]);
    }

    public function bulkTags(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $user->tenant_id;

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', 'in:add,remove,replace'],
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ]);

        if (in_array($validated['action'], ['add', 'remove'], true) && count($validated['tag_ids']) === 0) {
            return response()->json([
                'message' => 'Debe seleccionar al menos una etiqueta.',
                'errors' => ['tag_ids' => ['Seleccione al menos una etiqueta.']],
            ], 422);
        }

        $contacts = Contact::query()->whereIn('id', $validated['ids'])->get();

        $authorized = $contacts->filter(fn (Contact $contact) => $user->can('update', $contact));

        $pivotData = collect($validated['tag_ids'])->mapWithKeys(fn (int $tagId) => [
            $tagId => [
                'tenant_id' => $tenantId,
                'assigned_by' => $user->id,
            ],
        ])->all();

        foreach ($authorized as $contact) {
            match ($validated['action']) {
                'add' => $contact->tags()->syncWithoutDetaching($pivotData),
                'remove' => $contact->tags()->detach($validated['tag_ids']),
                'replace' => $contact->tags()->sync($pivotData),
            };
        }

        return response()->json([
            'updated' => $authorized->count(),
            'failed' => count($validated['ids']) - $authorized->count(),
            'action' => $validated['action'],
        ]);
    }

    /**
     * Updates the same set of simple fields for a selection of contacts.
     *
     * Each contact is authorized and validated independently. This preserves
     * the same rules as the single-contact editor while allowing permitted
     * contacts to succeed when another selected contact cannot be updated.
     */
    public function bulkFields(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
            'updates' => ['required', 'array', 'min:1'],
            'updates.name' => ['sometimes', 'required', 'string', 'max:255'],
            'updates.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'updates.email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'updates.source' => ['sometimes', 'required', 'string', 'in:whatsapp,instagram,facebook,manual'],
            'updates.custom_data' => ['sometimes', 'array'],
        ]);

        $updates = $validated['updates'];
        $allowedUpdateKeys = ['name', 'phone', 'email', 'source', 'custom_data'];
        $unknownUpdateKeys = array_diff(array_keys($updates), $allowedUpdateKeys);
        if ($unknownUpdateKeys !== []) {
            throw ValidationException::withMessages([
                'updates' => ['Contiene campos que no se pueden editar en lote.'],
            ]);
        }

        $customUpdates = (array) ($updates['custom_data'] ?? []);
        $fieldsByKey = ContactField::forTenant($user->tenant_id)->keyBy('key');
        foreach (array_keys($customUpdates) as $key) {
            $field = $fieldsByKey->get($key);
            if ($field === null) {
                throw ValidationException::withMessages([
                    "updates.custom_data.{$key}" => ['El campo no existe en este espacio.'],
                ]);
            }

            if ($field->is_unique || in_array($field->type, [ContactFieldType::File, ContactFieldType::Repeater], true)) {
                throw ValidationException::withMessages([
                    "updates.custom_data.{$key}" => ['Este campo no se puede editar en lote.'],
                ]);
            }
        }

        $contacts = Contact::query()->whereIn('id', $validated['ids'])->get()->keyBy('id');
        $updated = 0;
        $failures = [];

        foreach ($validated['ids'] as $id) {
            /** @var Contact|null $contact */
            $contact = $contacts->get($id);
            if ($contact === null || ! $user->can('update', $contact)) {
                $failures[] = ['id' => $id, 'reason' => 'No tienes permiso para actualizar este contacto.'];

                continue;
            }

            $payload = $updates;
            $providedCustomKeys = array_keys($customUpdates);
            if (array_key_exists('custom_data', $updates)) {
                $payload['custom_data'] = array_merge(
                    $contact->custom_data ?? [],
                    ContactCustomDataNormalizer::normalize($customUpdates, $contact->tenant_id),
                );
            }

            $validator = Validator::make($payload, $this->contactRules(
                partial: true,
                contactId: $contact->id,
                providedCustomKeys: $providedCustomKeys,
            ));

            if ($validator->fails()) {
                $failures[] = ['id' => $id, 'reason' => $validator->errors()->first()];

                continue;
            }

            try {
                $contact->update($validator->validated());
                $updated++;
            } catch (\Throwable $e) {
                report($e);
                $failures[] = ['id' => $id, 'reason' => 'No se pudo actualizar este contacto.'];
            }
        }

        return response()->json([
            'updated' => $updated,
            'failed' => count($failures),
            'failures' => $failures,
        ]);
    }

    /**
     * @param  list<string>|null  $providedCustomKeys  Custom field keys explicitly sent in this request, used to scope
     *                                                 required-field validation to a partial update.
     */
    private function contactRules(bool $partial = false, ?int $contactId = null, ?array $providedCustomKeys = null): array
    {
        $nameRule = $partial ? 'sometimes|required|string|max:255' : 'required|string|max:255';
        $user = request()->user();

        return [
            'name' => $nameRule,
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'source' => 'nullable|string|in:whatsapp,instagram,facebook,manual',
            'custom_data' => ['nullable', 'array', new ValidContactCustomData($contactId, $providedCustomKeys)],
            'branch_id' => BranchRuleResolver::rulesFor(
                $user,
                __('No tienes permiso para asignar contactos a esa sucursal.')
            ),
        ];
    }

    private function parseTagSlugs(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }
}
