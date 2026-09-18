<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportProductsRequest;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\ProductImport;
use App\Services\ContactImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactImportController extends Controller
{
    public function preview(ImportProductsRequest $request, ContactImportService $service): JsonResponse
    {
        $this->authorize('import', Contact::class);
        return response()->json(['data' => $service->preview($request->file('file'), $request->decodedMapping())]);
    }

    public function queue(ImportProductsRequest $request, ContactImportService $service): JsonResponse
    {
        $this->authorize('import', Contact::class);
        $user = $request->user();
        $mapping = $request->decodedMapping();
        if (($mapping['proposed_fields'] ?? []) !== [] && ! $user?->can('contact_fields.manage')) abort(403, 'No tenés permiso para crear campos.');
        $import = ProductImport::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id, 'resource' => 'contacts', 'requested_by' => $user->id,
            'original_filename' => (string) ($request->input('original_filename') ?: $request->file('file')->getClientOriginalName()),
            'file_path' => $request->file('file')->store("contact-imports/{$user->tenant_id}", 'local'),
            'sheet_name' => $request->input('sheet_name'), 'status' => 'queued', 'mode' => 'create', 'match_field' => 'name',
            'preserve_empty' => true, 'mapping' => $mapping, 'proposed_fields' => $mapping['proposed_fields'] ?? [],
            'preview' => $service->preview($request->file('file'), $mapping), 'queued_at' => now(), 'expires_at' => now()->addDays(7),
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

    private function serialize(ProductImport $import): array
    {
        return ['id' => $import->id, 'filename' => $import->original_filename, 'status' => $import->status, 'preview' => $import->preview, 'result' => $import->result, 'error' => $import->error];
    }
}
