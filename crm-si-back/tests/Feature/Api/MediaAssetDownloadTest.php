<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\MediaAsset;
use App\Models\Opportunity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MediaAssetDownloadTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_streams_the_file_inline_for_a_user_of_the_tenant(): void
    {
        ['user' => $user, 'asset' => $asset] = $this->setUpAsset();

        $response = $this->actingAs($user)->get("/api/media-assets/{$asset->id}/download");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        // inline y no attachment: el visor lo embebe en el panel.
        $this->assertStringContainsString('inline', $response->headers->get('content-disposition'));
        $this->assertSame('%PDF-1.4 contenido', $response->streamedContent());
    }

    #[Test]
    public function it_forces_a_download_when_asked(): void
    {
        ['user' => $user, 'asset' => $asset] = $this->setUpAsset();

        $response = $this->actingAs($user)->get("/api/media-assets/{$asset->id}/download?download=1");

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    #[Test]
    public function it_forbids_intermediaries_from_caching_a_tenant_file(): void
    {
        // El archivo es privado de un espacio: si un proxy lo cachea, puede
        // servirlo a otro usuario.
        ['user' => $user, 'asset' => $asset] = $this->setUpAsset();

        $response = $this->actingAs($user)->get("/api/media-assets/{$asset->id}/download");

        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertStringContainsString('private', $response->headers->get('cache-control'));
        // Servir inline un PDF de un tercero sin nosniff deja que el navegador
        // adivine el tipo del contenido.
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));
    }

    #[Test]
    public function it_hides_a_file_from_another_tenant(): void
    {
        ['asset' => $asset] = $this->setUpAsset();

        $otherTenant = $this->createTenantWithRoles('Otro');
        $intruder = User::factory()->create(['tenant_id' => $otherTenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherTenant->id);
        $intruder->assignRole('Admin');

        // 404 y no 403: un id de otro espacio no debe siquiera confirmarse que
        // existe.
        $this->actingAs($intruder)
            ->get("/api/media-assets/{$asset->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function it_requires_authentication(): void
    {
        // La razón de ser del endpoint: la URL pública de storage servía el
        // archivo a cualquiera.
        ['asset' => $asset] = $this->setUpAsset();

        $this->getJson("/api/media-assets/{$asset->id}/download")->assertUnauthorized();
    }

    #[Test]
    public function it_denies_a_member_who_is_not_assigned_to_the_contact(): void
    {
        // Un adjunto se autoriza como su contacto: sin contacts.view_any, el
        // Member sólo llega a los contactos que tiene asignados.
        ['tenant' => $tenant, 'asset' => $asset] = $this->setUpAsset();

        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $member->assignRole('Member');

        $this->actingAs($member)
            ->get("/api/media-assets/{$asset->id}/download")
            ->assertForbidden();
    }

    #[Test]
    public function it_allows_a_member_assigned_to_the_contact(): void
    {
        ['tenant' => $tenant, 'contact' => $contact, 'asset' => $asset] = $this->setUpAsset();

        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $member->assignRole('Member');

        Opportunity::create([
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'assigned_to' => $member->id,
            'title' => 'Alquiler',
            'status' => 'open',
        ]);

        // No se exige document_extraction.use: leer un archivo ya cargado no es
        // extraer datos.
        $this->actingAs($member)
            ->get("/api/media-assets/{$asset->id}/download")
            ->assertOk();
    }

    #[Test]
    public function a_library_asset_without_contact_keeps_its_historic_permission(): void
    {
        // Los archivos de la biblioteca de automations no cuelgan de ningún
        // contacto, así que no hay policy de contacto que aplicarles.
        $tenant = $this->createTenantWithRoles();
        Storage::fake('public');
        Storage::disk('public')->put('media-assets/lib.pdf', '%PDF-1.4');

        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'name' => 'lib.pdf',
            'path' => 'media-assets/lib.pdf',
            'mime_type' => 'application/pdf',
            'size' => 9,
        ]);

        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $member->assignRole('Member');

        $this->actingAs($member)->get("/api/media-assets/{$asset->id}/download")->assertForbidden();

        $admin = User::factory()->create(['tenant_id' => $tenant->id]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)->get("/api/media-assets/{$asset->id}/download")->assertOk();
    }

    #[Test]
    public function it_returns_404_when_the_row_outlived_the_file(): void
    {
        // El caso real: la purga de huérfanos borra del disco y custom_data
        // sigue apuntando al id. El front usa este 404 para ofrecer limpiar la
        // referencia en vez de mostrar un visor roto.
        ['user' => $user, 'asset' => $asset] = $this->setUpAsset();

        Storage::disk('public')->delete($asset->path);

        $this->actingAs($user)->get("/api/media-assets/{$asset->id}/download")->assertNotFound();
    }

    #[Test]
    public function meta_describes_the_file_without_serving_it(): void
    {
        ['user' => $user, 'asset' => $asset] = $this->setUpAsset();

        $response = $this->actingAs($user)->getJson("/api/media-assets/{$asset->id}/meta");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'contrato.pdf');
        $response->assertJsonPath('data.size', 17);
        $response->assertJsonPath('data.available', true);
    }

    #[Test]
    public function meta_reports_a_missing_file_as_unavailable(): void
    {
        // meta responde 200 con available:false en vez de 404: la cabecera del
        // visor necesita el nombre del archivo justamente para poder decir cuál
        // es el que ya no está.
        ['user' => $user, 'asset' => $asset] = $this->setUpAsset();

        Storage::disk('public')->delete($asset->path);

        $this->actingAs($user)
            ->getJson("/api/media-assets/{$asset->id}/meta")
            ->assertOk()
            ->assertJsonPath('data.available', false);
    }

    #[Test]
    public function meta_applies_the_same_authorization_as_the_download(): void
    {
        ['tenant' => $tenant, 'asset' => $asset] = $this->setUpAsset();

        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $member->assignRole('Member');

        $this->actingAs($member)
            ->getJson("/api/media-assets/{$asset->id}/meta")
            ->assertForbidden();
    }

    /**
     * @return array{tenant: Tenant, user: User, contact: Contact, asset: MediaAsset}
     */
    private function setUpAsset(): array
    {
        Storage::fake('public');

        $tenant = $this->createTenantWithRoles();

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole('Admin');

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Inquilino',
            'source' => 'manual',
        ]);

        $path = 'extractions/'.$tenant->id.'/contrato.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 contenido');

        $asset = MediaAsset::create([
            'tenant_id' => $tenant->id,
            'uploaded_by' => $user->id,
            'contact_id' => $contact->id,
            'name' => 'contrato.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 17,
            'purpose' => MediaAsset::PURPOSE_EXTRACTION,
        ]);

        return ['tenant' => $tenant, 'user' => $user, 'contact' => $contact, 'asset' => $asset];
    }
}
