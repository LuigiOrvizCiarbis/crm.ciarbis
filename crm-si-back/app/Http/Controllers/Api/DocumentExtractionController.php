<?php

namespace App\Http\Controllers\Api;

use App\Enums\ExtractionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ExtractDocumentDataJob;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\DocumentExtraction;
use App\Models\MediaAsset;
use App\Rules\ValidContactCustomData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentExtractionController extends Controller
{
    /**
     * Sube el documento sobre el que se va a extraer.
     *
     * Endpoint propio del contacto en vez del genérico POST /media-assets: aquel
     * está gateado por automations.manage, que el rol Member no tiene, y
     * abrirlo daría subida libre de archivos de hasta 100 MB sin vínculo con
     * ningún contacto autorizado.
     */
    public function upload(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeExtraction($request, $contact);

        $maxKilobytes = (int) ceil(((int) config('services.ai.extraction.max_file_bytes')) / 1024);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf', 'max:'.$maxKilobytes],
        ]);

        $file = $validated['file'];
        $path = $file->store('extractions/'.$contact->tenant_id, 'public');

        $asset = MediaAsset::create([
            'tenant_id' => $contact->tenant_id,
            'uploaded_by' => $request->user()->id,
            'contact_id' => $contact->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/pdf',
            'size' => $file->getSize(),
            'purpose' => MediaAsset::PURPOSE_EXTRACTION,
        ]);

        return response()->json([
            'data' => ['id' => $asset->id, 'name' => $asset->name, 'size' => $asset->size],
        ], 201);
    }

    /**
     * Encola la extracción. Devuelve 202: el resultado se consulta por show().
     */
    public function store(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeExtraction($request, $contact);

        $validated = $request->validate([
            'media_asset_id' => ['required', 'integer'],
        ]);

        $asset = MediaAsset::where('tenant_id', $contact->tenant_id)
            ->where('contact_id', $contact->id)
            ->where('purpose', MediaAsset::PURPOSE_EXTRACTION)
            ->find($validated['media_asset_id']);

        if (! $asset) {
            throw ValidationException::withMessages([
                'media_asset_id' => 'El documento no existe o no pertenece a este contacto.',
            ]);
        }

        if (! Storage::disk('public')->exists($asset->path)) {
            throw ValidationException::withMessages([
                'media_asset_id' => 'El documento ya no está disponible.',
            ]);
        }

        // Sin campos custom no hay nada que extraer, y el error es accionable:
        // el tenant tiene que configurar sus campos primero.
        if (ContactField::forTenant($contact->tenant_id)->isEmpty()) {
            throw ValidationException::withMessages([
                'media_asset_id' => 'Configurá campos personalizados de contacto antes de extraer datos.',
            ]);
        }

        $extraction = DocumentExtraction::create([
            'tenant_id' => $contact->tenant_id,
            'contact_id' => $contact->id,
            'media_asset_id' => $asset->id,
            'requested_by' => $request->user()->id,
            'status' => ExtractionStatus::Queued,
            'contact_lock_version' => $contact->lock_version,
        ]);

        // after_commit está en false en la config de colas: sin esto el worker
        // puede tomar el job antes de que la fila sea visible.
        DB::afterCommit(fn () => ExtractDocumentDataJob::dispatch($contact->tenant_id, $extraction->id));

        return response()->json(['data' => $this->serialize($extraction)], 202);
    }

    /**
     * Estado de la extracción. Es el endpoint que pollea el front.
     */
    public function show(Request $request, Contact $contact, DocumentExtraction $extraction): JsonResponse
    {
        $this->authorizeExtraction($request, $contact);
        $this->assertBelongsToContact($extraction, $contact);

        return response()->json(['data' => $this->serialize($extraction, withText: true)]);
    }

    /**
     * Aplica al contacto los campos que el usuario confirmó.
     *
     * No usa PUT /contacts/{id} porque ese endpoint mergea las claves recibidas
     * antes de validar y ValidContactCustomData sólo itera los campos que
     * existen: una clave cuyo ContactField fue borrado durante la revisión
     * entraría a custom_data sin validación.
     */
    public function confirm(Request $request, Contact $contact, DocumentExtraction $extraction): JsonResponse
    {
        $this->authorizeExtraction($request, $contact);
        $this->assertBelongsToContact($extraction, $contact);

        $validated = $request->validate([
            'fields' => ['present', 'array'],
            'lock_version' => ['required', 'integer'],
        ]);

        if ($extraction->status === ExtractionStatus::Confirmed) {
            // Reintento del mismo POST: no se vuelve a escribir.
            return response()->json([
                'data' => $this->serialize($extraction),
                'applied' => [],
                'discarded' => [],
            ]);
        }

        if ($extraction->status !== ExtractionStatus::Completed) {
            throw ValidationException::withMessages([
                'fields' => 'La extracción todavía no terminó.',
            ]);
        }

        $snapshot = $extraction->fields_snapshot ?? [];
        $liveKeys = ContactField::forTenant($contact->tenant_id)->pluck('key')->all();

        // Una clave sirve sólo si estaba cuando se extrajo Y sigue existiendo.
        $incoming = (array) $validated['fields'];
        $allowed = array_intersect(array_keys($incoming), array_keys($snapshot), $liveKeys);
        $applied = array_intersect_key($incoming, array_flip($allowed));
        $discarded = array_values(array_diff(array_keys($incoming), $allowed));

        return DB::transaction(function () use ($contact, $extraction, $applied, $discarded, $validated) {
            /** @var Contact $locked */
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);

            // Concurrencia optimista contra un contador, no contra updated_at:
            // la tabla tiene precisión de segundo y dos escrituras dentro del
            // mismo segundo compartirían timestamp.
            if ((int) $locked->lock_version !== (int) $validated['lock_version']) {
                return response()->json([
                    'message' => 'El contacto fue modificado desde que empezó la extracción.',
                    'error_code' => 'stale_contact',
                    'data' => ['lock_version' => (int) $locked->lock_version, 'custom_data' => $locked->custom_data],
                ], 409);
            }

            $merged = array_merge($locked->custom_data ?? [], $applied);

            $validator = validator(
                ['custom_data' => $merged],
                ['custom_data' => [new ValidContactCustomData(
                    ignoreContactId: $locked->id,
                    providedKeys: array_keys($applied),
                    tenantId: $locked->tenant_id,
                )]],
            );

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }

            $locked->custom_data = $merged;
            $locked->lock_version = (int) $locked->lock_version + 1;
            $locked->save();

            $extraction->update(['status' => ExtractionStatus::Confirmed]);

            return response()->json([
                'data' => $this->serialize($extraction->fresh()),
                'applied' => array_keys($applied),
                'discarded' => $discarded,
                'contact' => [
                    'lock_version' => $locked->lock_version,
                    'custom_data' => $locked->custom_data,
                ],
            ]);
        });
    }

    /**
     * Autoriza sobre el contacto (incluye chequeos de sucursal y asignación) y
     * sobre la feature.
     */
    private function authorizeExtraction(Request $request, Contact $contact): void
    {
        $this->authorize('update', $contact);
        abort_unless((bool) $request->user()?->can('document_extraction.use'), 403);
    }

    /**
     * Las rutas se declaran a mano, sin scoped bindings: sin este chequeo se
     * podría leer una extracción de otro contacto del mismo tenant.
     */
    private function assertBelongsToContact(DocumentExtraction $extraction, Contact $contact): void
    {
        abort_unless($extraction->contact_id === $contact->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(DocumentExtraction $extraction, bool $withText = false): array
    {
        $data = [
            'id' => $extraction->id,
            'status' => $extraction->status->value,
            'result' => $extraction->result,
            'text_coverage' => $extraction->text_coverage,
            'pages_without_text' => $extraction->pages_without_text ?? [],
            'error_code' => $extraction->error_code,
            'error_message' => $extraction->error_message,
            'contact_lock_version' => $extraction->contact_lock_version,
            'created_at' => $extraction->created_at,
        ];

        if ($withText) {
            $data['document_text'] = $extraction->document_text;
        }

        return $data;
    }
}
