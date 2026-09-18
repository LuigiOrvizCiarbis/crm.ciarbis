<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportProductsRequest;
use App\Jobs\ProcessProductImportJob;
use App\Models\ProductImport;
use App\Services\ProductImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductImportController extends Controller
{
    public function preview(ImportProductsRequest $request, ProductImportService $service): JsonResponse
    {
        $this->authorizeProducts($request);
        $mapping = $request->decodedMapping();
        $this->validateOptions($request, $mapping);
        $this->validateMatchField($request);
        return response()->json(['data' => $service->preview($request->file('file'), $mapping, (string) $request->input('match_field', 'name'))]);
    }

    public function queue(ImportProductsRequest $request, ProductImportService $service): JsonResponse
    {
        $this->authorizeProducts($request);
        $mapping = $request->decodedMapping();
        $this->validateOptions($request, $mapping);
        $this->validateMatchField($request);
        if ((array) ($mapping['proposed_fields'] ?? []) !== [] && ! $request->user()?->can('product_fields.manage')) abort(403, 'No tenés permiso para crear campos.');
        $user = $request->user();
        $import = ProductImport::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id, 'requested_by' => $user->id,
            'original_filename' => (string) ($request->input('original_filename') ?: $request->file('file')->getClientOriginalName()),
            'file_path' => $request->file('file')->store("product-imports/{$user->tenant_id}", 'local'),
            'sheet_name' => $request->input('sheet_name'), 'status' => 'queued',
            'mode' => $request->input('mode', 'create'), 'match_field' => $request->input('match_field', 'name'),
            'preserve_empty' => $request->boolean('preserve_empty', true), 'mapping' => $mapping,
            'proposed_fields' => $mapping['proposed_fields'] ?? [], 'preview' => $service->preview($request->file('file'), $mapping, (string) $request->input('match_field', 'name')),
            'queued_at' => now(), 'expires_at' => now()->addDays(7),
        ]);
        ProcessProductImportJob::dispatch($import->id, $user->tenant_id);
        return response()->json(['data' => $this->serialize($import)], 202);
    }

    public function show(Request $request, ProductImport $productImport): JsonResponse
    {
        $this->authorizeProducts($request); $this->authorizeTenant($request, $productImport);
        return response()->json(['data' => $this->serialize($productImport)]);
    }

    public function cancel(Request $request, ProductImport $productImport): JsonResponse
    {
        $this->authorizeProducts($request); $this->authorizeTenant($request, $productImport);
        if (! $productImport->cancel()) return response()->json(['message' => 'La importación ya comenzó.'], 409);
        return response()->json(['data' => $this->serialize($productImport->refresh())]);
    }

    public function errors(Request $request, ProductImport $productImport)
    {
        $this->authorizeProducts($request); $this->authorizeTenant($request, $productImport);
        $content = "fila,motivo\n";
        foreach ((array) data_get($productImport->result, 'error_rows', []) as $row) {
            $content .= ((int) ($row['row'] ?? 0)).',"'.str_replace('"', '""', (string) ($row['reason'] ?? '')).'"'.PHP_EOL;
        }
        return response($content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=errores-importacion-{$productImport->id}.csv"]);
    }

    private function validateOptions(Request $request, array $mapping): void
    {
        $request->validate(['mode' => ['nullable', Rule::in(['create', 'update', 'upsert'])], 'match_field' => ['nullable', 'string', 'max:80'], 'preserve_empty' => ['nullable', 'boolean'], 'original_filename' => ['nullable', 'string', 'max:255'], 'sheet_name' => ['nullable', 'string', 'max:120']]);
        if (($mapping['proposed_fields'] ?? []) !== [] && ! is_array($mapping['proposed_fields'])) abort(422, 'Los campos propuestos no son válidos.');
    }
    private function authorizeProducts(Request $request): void { if (! $request->user()?->can('products.manage')) abort(403); }
    private function validateMatchField(Request $request): void
    {
        $match = (string) $request->input('match_field', 'name');
        if ($match === 'name') return;
        if (! str_starts_with($match, 'custom:')) abort(422, 'El identificador no es válido.');
        $key = substr($match, 7);
        $field = \App\Models\ProductField::forTenant((int) $request->user()->tenant_id)->firstWhere('key', $key);
        if (! $field?->is_unique) abort(422, 'El identificador debe ser un campo único.');
    }
    private function authorizeTenant(Request $request, ProductImport $import): void { if ($import->tenant_id !== $request->user()?->tenant_id) abort(404); }
    /** @return array<string, mixed> */
    private function serialize(ProductImport $import): array
    {
        return ['id' => $import->id, 'filename' => $import->original_filename, 'sheet_name' => $import->sheet_name, 'status' => $import->status, 'mode' => $import->mode, 'match_field' => $import->match_field, 'preview' => $import->preview, 'result' => $import->result, 'created_fields' => $import->created_fields, 'error' => $import->error, 'queued_at' => $import->queued_at?->toIso8601String(), 'started_at' => $import->started_at?->toIso8601String(), 'finished_at' => $import->finished_at?->toIso8601String()];
    }
}
