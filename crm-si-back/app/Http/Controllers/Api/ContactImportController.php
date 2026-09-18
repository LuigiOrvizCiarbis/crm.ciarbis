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

        return response()->json(['data' => $service->preview($request->file('file'), $request->decodedMapping(), $this->matchField($request), $this->mode($request))]);
    }

    public function queue(ImportProductsRequest $request, ContactImportService $service): JsonResponse
    {
        $this->authorize('import', Contact::class);
        $this->validateOptions($request);
        $user = $request->user();
        $mapping = $request->decodedMapping();
        $matchField = $this->matchField($request);
        if (($mapping['proposed_fields'] ?? []) !== [] && ! $user?->can('contact_fields.manage')) {
            abort(403, 'No tenés permiso para crear campos.');
        }
        $import = ProductImport::withoutGlobalScopes()->create([
            'tenant_id' => $user->tenant_id, 'resource' => 'contacts', 'requested_by' => $user->id,
            'original_filename' => (string) ($request->input('original_filename') ?: $request->file('file')->getClientOriginalName()),
            'file_path' => $request->file('file')->store("contact-imports/{$user->tenant_id}", 'local'),
            'sheet_name' => $request->input('sheet_name'), 'status' => 'queued',
            'mode' => $this->mode($request), 'match_field' => $matchField,
            'preserve_empty' => $request->boolean('preserve_empty', true),
            'mapping' => $mapping, 'proposed_fields' => $mapping['proposed_fields'] ?? [],
            'preview' => $service->preview($request->file('file'), $mapping, $matchField, $this->mode($request)), 'queued_at' => now(), 'expires_at' => now()->addDays(7),
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
        if (! $productImport->cancel()) {
            return response()->json(['message' => 'La importación ya comenzó.'], 409);
        }

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
        if (in_array($match, self::NATIVE_MATCH_FIELDS, true)) {
            return;
        }
        if (! str_starts_with($match, 'custom:')) {
            abort(422, 'El identificador no es válido.');
        }
        $field = ContactField::query()->where('tenant_id', (int) $request->user()->tenant_id)
            ->whereNull('deleted_at')->firstWhere('key', substr($match, 7));
        if (! $field?->is_unique) {
            abort(422, 'El identificador debe ser un campo único.');
        }
    }

    private function matchField(Request $request): string
    {
        return (string) ($request->input('match_field') ?: 'name');
    }

    private function mode(Request $request): string
    {
        return (string) ($request->input('mode') ?: 'create');
    }

    /**
     * Descarga de todas las filas con error. `error_rows` viene recortada para
     * el diálogo, así que el CSV usa `error_rows_all`, que es la lista entera:
     * con miles de errores, ver los primeros 50 no alcanza para arreglar nada.
     */
    public function errors(Request $request, ProductImport $productImport)
    {
        $this->authorize('import', Contact::class);
        abort_unless($productImport->tenant_id === $request->user()?->tenant_id && $productImport->resource === 'contacts', 404);

        $rows = data_get($productImport->result, 'error_rows_all')
            ?? data_get($productImport->result, 'error_rows', []);

        // BOM para que Excel reconozca UTF-8 y no rompa los acentos.
        $content = "\xEF\xBB\xBF";
        $content .= implode(',', ['fila', 'nombre', 'telefono', 'email', 'identificador', 'fila_en_conflicto', 'motivo']).PHP_EOL;
        foreach ((array) $rows as $row) {
            $content .= implode(',', [
                (int) ($row['row'] ?? 0),
                self::csvCell($row['name'] ?? ''),
                self::csvCell($row['phone'] ?? ''),
                self::csvCell($row['email'] ?? ''),
                self::csvCell($row['identifier'] ?? ''),
                (int) ($row['conflicts_with_row'] ?? 0) ?: '',
                self::csvCell($row['reason'] ?? ''),
            ]).PHP_EOL;
        }

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=errores-importacion-{$productImport->id}.csv",
        ]);
    }

    /**
     * Celda de CSV con los datos del archivo importado, que son texto ajeno.
     *
     * Además de escapar las comillas, neutraliza la inyección de fórmulas:
     * Excel y LibreOffice interpretan como fórmula toda celda que arranque con
     * `=`, `+`, `-` o `@`, así que un contacto llamado `=cmd|...` se ejecutaría
     * al abrir el reporte. El apóstrofo inicial fuerza la lectura como texto y
     * las dos planillas lo ocultan al mostrarlo.
     *
     * Un teléfono como `+5491122223333` empieza con `+` pero es sólo dígitos y
     * separadores, y ninguna planilla lo evalúa: se deja intacto para no llenar
     * de apóstrofos la columna más común del reporte.
     */
    /**
     * Un teléfono internacional (`+54 9 11 2222-3333`) arranca con `+`, pero
     * sin operadores después del prefijo ninguna planilla lo evalúa. Se exceptúa
     * para no llenar de apóstrofos la columna más común del reporte; cualquier
     * otra cosa que empiece con un carácter peligroso sí se escapa, incluida la
     * aritmética como `-2+3`.
     */
    private static function isPhoneLike(string $text): bool
    {
        return preg_match('/^\+[\d\s().-]+$/', $text) === 1;
    }

    private static function csvCell(mixed $value): string
    {
        $text = (string) $value;
        if ($text !== '' && str_contains("=+-@\t\r", $text[0]) && ! self::isPhoneLike($text)) {
            $text = "'".$text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }

    private function serialize(ProductImport $import): array
    {
        return ['id' => $import->id, 'filename' => $import->original_filename, 'status' => $import->status, 'mode' => $import->mode, 'match_field' => $import->match_field, 'preview' => $import->preview, 'result' => $import->result, 'error' => $import->error];
    }
}
