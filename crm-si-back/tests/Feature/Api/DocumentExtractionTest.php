<?php

namespace Tests\Feature\Api;

use App\Enums\ContactFieldType;
use App\Enums\ExtractionStatus;
use App\Jobs\ExtractDocumentDataJob;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\DocumentExtraction;
use App\Models\MediaAsset;
use App\Models\Opportunity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DocumentExtractionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_uploads_a_pdf_and_queues_the_extraction(): void
    {
        Storage::fake('public');
        Queue::fake();

        ['user' => $user, 'contact' => $contact] = $this->setUpTenant();

        $upload = $this->actingAs($user)->postJson("/api/contacts/{$contact->id}/documents", [
            'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ]);

        $upload->assertCreated();
        $assetId = $upload->json('data.id');

        $response = $this->actingAs($user)->postJson("/api/contacts/{$contact->id}/extractions", [
            'media_asset_id' => $assetId,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(ExtractDocumentDataJob::class);
        $this->assertDatabaseHas('media_assets', [
            'id' => $assetId,
            'contact_id' => $contact->id,
            'purpose' => MediaAsset::PURPOSE_EXTRACTION,
        ]);
    }

    #[Test]
    public function a_member_can_upload_without_the_automations_permission(): void
    {
        // El endpoint genérico /media-assets exige automations.manage, que el
        // rol operativo no tiene: por eso el upload es propio del contacto.
        Storage::fake('public');

        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant(role: 'Member');

        // Member no tiene contacts.view_any, así que la policy exige que el
        // contacto le esté asignado por una oportunidad o conversación.
        Opportunity::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'assigned_to' => $user->id,
            'title' => 'Alquiler',
            'status' => 'open',
            'source_type' => 'manual',
        ]);

        $this->assertFalse($user->can('automations.manage'));

        $this->actingAs($user)
            ->postJson("/api/contacts/{$contact->id}/documents", [
                'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
            ])
            ->assertCreated();
    }

    #[Test]
    public function it_rejects_a_document_belonging_to_another_contact(): void
    {
        Storage::fake('public');
        Queue::fake();

        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();
        $otherContact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Otro',
            'source' => 'manual',
        ]);

        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $otherContact->id,
            'name' => 'ajeno.pdf',
            'path' => 'extractions/x.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
            'purpose' => MediaAsset::PURPOSE_EXTRACTION,
        ]);

        $this->actingAs($user)
            ->postJson("/api/contacts/{$contact->id}/extractions", ['media_asset_id' => $asset->id])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_hides_an_extraction_that_belongs_to_another_contact(): void
    {
        // Las rutas no usan scoped bindings: sin el chequeo explícito se podría
        // leer la extracción de otro contacto del mismo tenant.
        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();
        $otherContact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Otro',
            'source' => 'manual',
        ]);

        $extraction = $this->makeExtraction($tenant, $otherContact);

        $this->actingAs($user)
            ->getJson("/api/contacts/{$contact->id}/extractions/{$extraction->id}")
            ->assertNotFound();
    }

    #[Test]
    public function it_applies_the_confirmed_fields_to_the_contact(): void
    {
        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();
        $extraction = $this->makeExtraction($tenant, $contact, [
            'status' => ExtractionStatus::Completed,
            'result' => ['monto' => 150000],
            'fields_snapshot' => ['monto' => 'number'],
        ]);

        $response = $this->actingAs($user)->postJson(
            "/api/contacts/{$contact->id}/extractions/{$extraction->id}/confirm",
            ['fields' => ['monto' => 150000], 'lock_version' => 0],
        );

        $response->assertOk();
        $response->assertJsonPath('applied', ['monto']);

        $contact->refresh();
        $this->assertSame(150000, $contact->custom_data['monto']);
        // El contador sube para invalidar confirmaciones basadas en el estado viejo.
        $this->assertSame(1, (int) $contact->lock_version);
        $this->assertSame(ExtractionStatus::Confirmed, $extraction->fresh()->status);
    }

    #[Test]
    public function it_discards_keys_whose_field_was_deleted_during_review(): void
    {
        // ValidContactCustomData sólo itera los campos que existen y no rechaza
        // claves desconocidas: sin la intersección con el snapshot, una clave
        // huérfana entraría a custom_data sin validación.
        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();

        $borrado = ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'indice',
            'label' => 'Índice',
            'type' => ContactFieldType::Text,
        ]);

        $extraction = $this->makeExtraction($tenant, $contact, [
            'status' => ExtractionStatus::Completed,
            'result' => ['monto' => 150000, 'indice' => 'ICL'],
            'fields_snapshot' => ['monto' => 'number', 'indice' => 'text'],
        ]);

        $borrado->delete();
        ContactField::clearTenantCache($tenant->id);

        $response = $this->actingAs($user)->postJson(
            "/api/contacts/{$contact->id}/extractions/{$extraction->id}/confirm",
            ['fields' => ['monto' => 150000, 'indice' => 'ICL'], 'lock_version' => 0],
        );

        $response->assertOk();
        $response->assertJsonPath('applied', ['monto']);
        $response->assertJsonPath('discarded', ['indice']);

        $contact->refresh();
        $this->assertArrayNotHasKey('indice', $contact->custom_data);
    }

    #[Test]
    public function it_returns_409_when_the_contact_changed_since_the_extraction_started(): void
    {
        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();
        $extraction = $this->makeExtraction($tenant, $contact, [
            'status' => ExtractionStatus::Completed,
            'result' => ['monto' => 150000],
            'fields_snapshot' => ['monto' => 'number'],
        ]);

        // Alguien editó el contacto mientras el usuario revisaba.
        $contact->update(['lock_version' => 5, 'custom_data' => ['monto' => 999]]);

        $response = $this->actingAs($user)->postJson(
            "/api/contacts/{$contact->id}/extractions/{$extraction->id}/confirm",
            ['fields' => ['monto' => 150000], 'lock_version' => 0],
        );

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'stale_contact');

        // No se pisó la edición ajena.
        $this->assertSame(999, $contact->fresh()->custom_data['monto']);
    }

    #[Test]
    public function an_ordinary_contact_edit_invalidates_a_pending_confirmation(): void
    {
        // El caso real de una edición concurrente: alguien toca el contacto por
        // la vía normal mientras otro revisa una extracción. Si sólo la
        // confirmación avanzara el contador, esa edición se pisaría en silencio.
        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();
        $extraction = $this->makeExtraction($tenant, $contact, [
            'status' => ExtractionStatus::Completed,
            'result' => ['monto' => 150000],
            'fields_snapshot' => ['monto' => 'number'],
        ]);

        $this->actingAs($user)
            ->putJson("/api/contacts/{$contact->id}", ['custom_data' => ['monto' => 999]])
            ->assertOk();

        $this->assertSame(1, (int) $contact->fresh()->lock_version);

        $this->actingAs($user)
            ->postJson("/api/contacts/{$contact->id}/extractions/{$extraction->id}/confirm", [
                'fields' => ['monto' => 150000],
                'lock_version' => 0,
            ])
            ->assertStatus(409);

        $this->assertSame(999, $contact->fresh()->custom_data['monto']);
    }

    #[Test]
    public function a_confirmation_retry_returns_the_contact_instead_of_nothing(): void
    {
        // Si el reintento no devolviera el contacto, el front interpretaría la
        // ausencia como custom_data vacío y borraría su copia local.
        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();
        $extraction = $this->makeExtraction($tenant, $contact, [
            'status' => ExtractionStatus::Completed,
            'result' => ['monto' => 150000],
            'fields_snapshot' => ['monto' => 'number'],
        ]);

        $url = "/api/contacts/{$contact->id}/extractions/{$extraction->id}/confirm";
        $payload = ['fields' => ['monto' => 150000], 'lock_version' => 0];

        $this->actingAs($user)->postJson($url, $payload)->assertOk();

        $retry = $this->actingAs($user)->postJson($url, $payload);

        $retry->assertOk();
        $retry->assertJsonPath('contact.custom_data.monto', 150000);
    }

    #[Test]
    public function confirming_twice_does_not_apply_the_values_again(): void
    {
        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();
        $extraction = $this->makeExtraction($tenant, $contact, [
            'status' => ExtractionStatus::Completed,
            'result' => ['monto' => 150000],
            'fields_snapshot' => ['monto' => 'number'],
        ]);

        $url = "/api/contacts/{$contact->id}/extractions/{$extraction->id}/confirm";
        $payload = ['fields' => ['monto' => 150000], 'lock_version' => 0];

        $this->actingAs($user)->postJson($url, $payload)->assertOk();
        $this->actingAs($user)->postJson($url, $payload)->assertOk();

        // El segundo POST no vuelve a escribir: el contador quedó en 1.
        $this->assertSame(1, (int) $contact->fresh()->lock_version);
    }

    #[Test]
    public function it_rejects_confirmation_before_the_extraction_finished(): void
    {
        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();
        $extraction = $this->makeExtraction($tenant, $contact);

        $this->actingAs($user)
            ->postJson("/api/contacts/{$contact->id}/extractions/{$extraction->id}/confirm", [
                'fields' => ['monto' => 1],
                'lock_version' => 0,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function it_denies_access_without_the_extraction_permission(): void
    {
        Storage::fake('public');

        ['user' => $user, 'contact' => $contact, 'tenant' => $tenant] = $this->setUpTenant();

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->roles->first()->revokePermissionTo('document_extraction.use');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user->fresh())
            ->postJson("/api/contacts/{$contact->id}/documents", [
                'file' => UploadedFile::fake()->create('contrato.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    #[Test]
    public function it_rejects_a_non_pdf_upload(): void
    {
        Storage::fake('public');

        ['user' => $user, 'contact' => $contact] = $this->setUpTenant();

        $this->actingAs($user)
            ->postJson("/api/contacts/{$contact->id}/documents", [
                'file' => UploadedFile::fake()->image('foto.png'),
            ])
            ->assertStatus(422);
    }

    /**
     * @return array{tenant: Tenant, user: User, contact: Contact}
     */
    private function setUpTenant(string $role = 'Admin'): array
    {
        $tenant = $this->createTenantWithRoles();

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole($role);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'monto',
            'label' => 'Monto del alquiler',
            'type' => ContactFieldType::Number,
        ]);
        ContactField::clearTenantCache($tenant->id);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inquilino',
            'source' => 'manual',
        ]);

        return ['tenant' => $tenant, 'user' => $user, 'contact' => $contact];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeExtraction(Tenant $tenant, Contact $contact, array $attributes = []): DocumentExtraction
    {
        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'name' => 'contrato.pdf',
            'path' => 'extractions/'.$tenant->id.'/contrato.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'purpose' => MediaAsset::PURPOSE_EXTRACTION,
        ]);

        return DocumentExtraction::create(array_merge([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'media_asset_id' => $asset->id,
            'status' => ExtractionStatus::Queued,
            'contact_lock_version' => 0,
        ], $attributes));
    }
}
