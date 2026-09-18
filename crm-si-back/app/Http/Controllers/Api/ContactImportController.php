<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportProductsRequest;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ProductImport;
use App\Services\ContactImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactImportController extends Controller
{
    /** Identificadores nativos admitidos para emparejar filas con contactos. */
    private const NATIVE_MATCH_FIELDS = ['name', 'phone', 'email'];

    public function preview(ImportProductsRequest $request, ContactImportService $service): JsonResponse
    {
        $this->authorize('import', Contact::class);
        $this->validateOptions($request);
        return response()->json(['data' => $service->preview($request->file('file'), $request->decodedMapping(), $this->matchField($request))]);
    }

    public function queue(ImportProductsRequest $request, ContactImportService $service): JsonResponse
    {
        $this->authorize('import', Contact::class);
        $this->validateOptions($request);
        $user = $request->user();
        $mapping = $request->decodedMapping();
        $matchField = $this->matchField($request);
        if (($mapping['proposed_fields'] ?? []) !== [] && ! $user?->can('contact_fields.manage')) abort(403, 'No tenés permiso para crear campos.');
        $import = ProductImport::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id, 'resource' => 'contacts', 'requested_by' => $user->id,
            'original_filename' => (string) ($request->input('original_filename') ?: $request->file('file')->getClientOriginalName()),
            'file_path' => $request->file('file')->store("contact-imports/{$user->tenant_id}", 'local'),
            'sheet_name' => $request->input('sheet_name'), 'status' => 'queued',
            'mode' => (string) $request->input('mode', 'create'), 'match_field' => $matchField,
            'preserve_empty' => $request->boolean('preserve_empty', true),
            'mapping' => $mapping, 'proposed_fields' => $mapping['proposed_fields'] ?? [],
            'preview' => $service->preview($request->file('file'), $mapping, $matchField), 'queued_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        ProcessContactImportJob::dispatch($import->id, $user->tenant_id);
        return response()->json(['data' => $this->serialize($import)], 202);
    }

    public function show(Request $request, ProductImport $productImport): JsonResponse
    {
        $this->authorize('import', Contact::class);
        abort_unless($productImport->tenant_id === $request->user()?->tenant_id && $productImport->resource === 'contacts', 404);
        return response()->json(['data' => $this->serialize($productImport)]);
    }

    public function cancel(Request $request, ProductImport $productImport): JsonResponse
    {
        $this->authorize('import', Contact::class);
        abort_unless($productImport->tenant_id === $request->user()?->tenant_id && $productImport->resource === 'contacts', 404);
        if (! $productImport->cancel()) return response()->json(['message' => 'La importación ya comenzó.'], 409);
        return response()->json(['data' => $this->serialize($productImport->refresh())]);
    }

    private function validateOptions(Request $request): void
    {
        $request->validate([
            'mode' => ['nullable', Rule::in(['create', 'update', 'upsert'])],
            'match_field' => ['nullable', 'string', 'max:80'],
            'preserve_empty' => ['nullable', 'boolean'],
        ]);
        $match = $this->matchField($request);
        if (in_array($match, self::NATIVE_MATCH_FIELDS, true)) return;
        if (! str_starts_with($match, 'custom:')) abort(422, 'El identificador no es válido.');
        $field = ContactField::query()->where('tenant_id', (int) $request->user()->tenant_id)
            ->whereNull('deleted_at')->firstWhere('key', substr($match, 7));
        if (! $field?->is_unique) abort(422, 'El identificador debe ser un campo único.');
    }

    private function matchField(Request $request): string
    {
        return (string) ($request->input('match_field') ?: 'name');
    }

    private function serialize(ProductImport $import): array
    {
        return ['id' => $import->id, 'filename' => $import->original_filename, 'status' => $import->status, 'mode' => $import->mode, 'match_field' => $import->match_field, 'preview' => $import->preview, 'result' => $import->result, 'error' => $import->error];
    }
}
