<?php

namespace Tests\Feature;

use App\Enums\ContactFieldType;
use App\Enums\ExtractionStatus;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\DocumentExtraction;
use App\Models\MediaAsset;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurgeOrphanExtractionDocumentsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_deletes_an_upload_that_was_never_used(): void
    {
        // El upload y el encolado son dos requests: si el usuario cierra el
        // diálogo entre medio, el archivo queda sin referencias.
        Storage::fake('public');
        $tenant = $this->createTenantWithRoles();

        $asset = $this->makeAsset($tenant, 'abandonado.pdf', daysOld: 3);

        $this->artisan('extractions:purge-orphans')->assertSuccessful();

        // withoutGlobalScopes() también quita el scope de SoftDeletes, así que
        // el descarte se comprueba por deleted_at, no por ausencia de fila.
        $this->assertNotNull(
            MediaAsset::withoutGlobalScopes()->find($asset->id)?->deleted_at,
        );
        Storage::disk('public')->assertMissing($asset->path);
    }

    #[Test]
    public function it_keeps_a_recent_upload(): void
    {
        // Puede ser un diálogo abierto en este momento.
        Storage::fake('public');
        $tenant = $this->createTenantWithRoles();

        $asset = $this->makeAsset($tenant, 'reciente.pdf', daysOld: 0);

        $this->artisan('extractions:purge-orphans')->assertSuccessful();

        $this->assertNull(MediaAsset::withoutGlobalScopes()->find($asset->id)?->deleted_at);
    }

    #[Test]
    public function it_keeps_a_document_referenced_by_an_extraction(): void
    {
        // Incluso si la extracción falló: el usuario puede querer ver qué pasó.
        Storage::fake('public');
        $tenant = $this->createTenantWithRoles();
        $contact = $this->makeContact($tenant);
        $asset = $this->makeAsset($tenant, 'usado.pdf', daysOld: 30, contact: $contact);

        DocumentExtraction::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'media_asset_id' => $asset->id,
            'status' => ExtractionStatus::Failed,
        ]);

        $this->artisan('extractions:purge-orphans')->assertSuccessful();

        $this->assertNull(MediaAsset::withoutGlobalScopes()->find($asset->id)?->deleted_at);
        Storage::disk('public')->assertExists($asset->path);
    }

    #[Test]
    public function it_keeps_a_document_attached_to_a_file_field(): void
    {
        // El usuario adjuntó el PDF al contacto además de extraerlo: dejó de
        // ser un temporal y borrarlo rompería el campo.
        Storage::fake('public');
        $tenant = $this->createTenantWithRoles();

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'contrato_pdf',
            'label' => 'Contrato (PDF)',
            'type' => ContactFieldType::File,
        ]);

        $contact = $this->makeContact($tenant);
        $asset = $this->makeAsset($tenant, 'adjunto.pdf', daysOld: 30, contact: $contact);

        $contact->update(['custom_data' => ['contrato_pdf' => $asset->id]]);

        $this->artisan('extractions:purge-orphans')->assertSuccessful();

        $this->assertNull(MediaAsset::withoutGlobalScopes()->find($asset->id)?->deleted_at);
        Storage::disk('public')->assertExists($asset->path);
    }

    #[Test]
    public function it_never_touches_the_automation_media_library(): void
    {
        // Los adjuntos de automatizaciones viven en el mismo modelo pero con
        // otro propósito y su propio ciclo de vida.
        Storage::fake('public');
        $tenant = $this->createTenantWithRoles();

        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'name' => 'catalogo.pdf',
            'path' => 'media-assets/'.$tenant->id.'/catalogo.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'purpose' => MediaAsset::PURPOSE_LIBRARY,
        ]);
        MediaAsset::withoutGlobalScopes()->whereKey($asset->id)->update(['created_at' => now()->subDays(90)]);

        $this->artisan('extractions:purge-orphans')->assertSuccessful();

        $this->assertNull(MediaAsset::withoutGlobalScopes()->find($asset->id)?->deleted_at);
    }

    #[Test]
    public function dry_run_reports_without_deleting(): void
    {
        Storage::fake('public');
        $tenant = $this->createTenantWithRoles();
        $asset = $this->makeAsset($tenant, 'abandonado.pdf', daysOld: 3);

        $this->artisan('extractions:purge-orphans', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(MediaAsset::withoutGlobalScopes()->find($asset->id)?->deleted_at);
        Storage::disk('public')->assertExists($asset->path);
    }

    private function makeContact(Tenant $tenant): Contact
    {
        return Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inquilino',
            'source' => 'manual',
        ]);
    }

    private function makeAsset(Tenant $tenant, string $name, int $daysOld, ?Contact $contact = null): MediaAsset
    {
        $path = 'extractions/'.$tenant->id.'/'.$name;
        Storage::disk('public')->put($path, '%PDF-1.4 fake');

        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact?->id,
            'name' => $name,
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 13,
            'purpose' => MediaAsset::PURPOSE_EXTRACTION,
        ]);

        if ($daysOld > 0) {
            MediaAsset::withoutGlobalScopes()
                ->whereKey($asset->id)
                ->update(['created_at' => now()->subDays($daysOld)]);
        }

        return $asset->fresh();
    }
}
