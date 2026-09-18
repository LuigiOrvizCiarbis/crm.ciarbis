<?php

namespace Tests\Feature;

use App\Enums\ContactFieldType;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\ProductImport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ContactImportService;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Modos del importador de contactos (crear / actualizar / crear y actualizar).
 *
 * Hasta ahora el importador de contactos sólo insertaba: un contacto ya
 * existente se descartaba como duplicado y no había forma de actualizarlo,
 * a diferencia del importador de catálogo.
 */
class ContactImportModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_modo_create_no_pisa_un_contacto_existente(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana Vieja', 'phone' => '+5491122223333', 'source' => 'manual']);

        $result = $this->runImport($tenant, "nombre,telefono\nAna Nueva,+54 9 11 2222-3333\n", 'create', 'phone');

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertSame('Ana Vieja', Contact::where('tenant_id', $tenant->id)->sole()->name);
    }

    public function test_modo_update_actualiza_por_telefono_normalizado(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana Vieja', 'phone' => '+5491122223333', 'source' => 'manual']);

        // El archivo trae el mismo número con separadores: debe emparejar igual.
        $result = $this->runImport($tenant, "nombre,telefono,email\nAna Nueva,+54 9 11 2222-3333,ana@acme.com\n", 'update', 'phone');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['created']);
        $contact->refresh();
        $this->assertSame('Ana Nueva', $contact->name);
        $this->assertSame('ana@acme.com', $contact->email);
    }

    public function test_modo_update_omite_las_filas_que_no_existen(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $result = $this->runImport($tenant, "nombre,telefono\nNadie,+5491199998888\n", 'update', 'phone');

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertSame(0, Contact::where('tenant_id', $tenant->id)->count());
    }

    public function test_modo_upsert_crea_y_actualiza_en_la_misma_corrida(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana Vieja', 'phone' => '+5491122223333', 'source' => 'manual']);

        $result = $this->runImport($tenant, "nombre,telefono\nAna Nueva,+5491122223333\nBruno,+5491144445555\n", 'upsert', 'phone');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(2, Contact::where('tenant_id', $tenant->id)->count());
    }

    public function test_conservar_vacios_no_borra_los_datos_ya_cargados(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana', 'phone' => '+5491122223333', 'email' => 'ana@acme.com', 'source' => 'manual']);

        $this->runImport($tenant, "nombre,telefono,email\nAna,+5491122223333,\n", 'update', 'phone', true);

        $this->assertSame('ana@acme.com', $contact->fresh()->email);
    }

    public function test_sin_conservar_vacios_una_celda_vacia_limpia_el_campo(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana', 'phone' => '+5491122223333', 'email' => 'ana@acme.com', 'source' => 'manual']);

        $this->runImport($tenant, "nombre,telefono,email\nAna,+5491122223333,\n", 'update', 'phone', false);

        $this->assertNull($contact->fresh()->email);
    }

    public function test_actualiza_por_campo_personalizado_unico(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        ContactField::create(['tenant_id' => $tenant->id, 'key' => 'dni', 'label' => 'DNI', 'type' => ContactFieldType::Text,
            'is_required' => false, 'is_unique' => true, 'display_order' => 1]);
        $contact = Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana Vieja', 'phone' => '+5491122223333', 'custom_data' => ['dni' => '30111222'], 'source' => 'manual']);

        $result = $this->runImport(
            $tenant,
            "nombre,dni\nAna Nueva,30111222\n",
            'update',
            'custom:dni',
            true,
            ['name' => 0, 'custom' => ['dni' => 1]],
        );

        $this->assertSame(1, $result['updated']);
        $this->assertSame('Ana Nueva', $contact->fresh()->name);
    }

    public function test_un_telefono_de_otro_contacto_no_se_pisa_al_actualizar(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana', 'phone' => '+5491122223333', 'email' => 'ana@acme.com', 'source' => 'manual']);
        Contact::create(['tenant_id' => $tenant->id, 'name' => 'Bruno', 'phone' => '+5491144445555', 'email' => 'bruno@acme.com', 'source' => 'manual']);

        // La fila identifica a Bruno por email, pero le asigna el teléfono de Ana.
        $result = $this->runImport($tenant, "nombre,telefono,email\nBruno,+5491122223333,bruno@acme.com\n", 'update', 'email');

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['errors']);
        $this->assertSame('+5491144445555', Contact::where('name', 'Bruno')->sole()->phone);
    }

    public function test_el_identificador_repetido_en_el_archivo_no_se_procesa_dos_veces(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $result = $this->runImport($tenant, "nombre,telefono\nAna,+5491122223333\nAna Bis,+54 9 11 2222-3333\n", 'upsert', 'phone');

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['errors']);
    }

    public function test_el_endpoint_guarda_el_modo_elegido(): void
    {
        [$tenant, $user] = $this->authenticatedOwner();

        $this->postJson('/api/contacts/import/queue', [
            'file' => UploadedFile::fake()->createWithContent('contactos.csv', "nombre,telefono\nAna,+5491122223333\n"),
            'mapping' => json_encode(['has_headers' => true, 'name' => 0, 'phone' => 1]),
            'mode' => 'upsert', 'match_field' => 'phone', 'preserve_empty' => '0',
        ])->assertStatus(202)->assertJsonPath('data.mode', 'upsert')->assertJsonPath('data.match_field', 'phone');

        $import = ProductImport::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame('upsert', $import->mode);
        $this->assertSame('phone', $import->match_field);
        $this->assertFalse($import->preserve_empty);
    }

    public function test_el_endpoint_rechaza_un_modo_desconocido(): void
    {
        $this->authenticatedOwner();

        $this->postJson('/api/contacts/import/queue', [
            'file' => UploadedFile::fake()->createWithContent('contactos.csv', "nombre,telefono\nAna,+5491122223333\n"),
            'mapping' => json_encode(['has_headers' => true, 'name' => 0, 'phone' => 1]),
            'mode' => 'borrar', 'match_field' => 'phone',
        ])->assertStatus(422);
    }

    public function test_el_endpoint_rechaza_identificar_por_un_campo_no_unico(): void
    {
        [$tenant] = $this->authenticatedOwner();
        ContactField::create(['tenant_id' => $tenant->id, 'key' => 'apodo', 'label' => 'Apodo', 'type' => ContactFieldType::Text,
            'is_required' => false, 'is_unique' => false, 'display_order' => 1]);

        $this->postJson('/api/contacts/import/queue', [
            'file' => UploadedFile::fake()->createWithContent('contactos.csv', "nombre,apodo\nAna,Anita\n"),
            'mapping' => json_encode(['has_headers' => true, 'name' => 0, 'custom' => ['apodo' => 1]]),
            'mode' => 'update', 'match_field' => 'custom:apodo',
        ])->assertStatus(422);
    }

    /** @return array{0: Tenant, 1: User} */
    private function authenticatedOwner(): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        foreach (PermissionCatalog::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $registrar->forgetCachedPermissions();

        $tenant = Tenant::create(['name' => 'Acme '.uniqid()]);
        app(RoleProvisioner::class)->provisionDefaultRoles($tenant);
        $registrar->setPermissionsTeamId($tenant->id);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');
        Sanctum::actingAs($user);

        return [$tenant, $user];
    }

    /**
     * @param  array<string, mixed>|null  $mapping
     * @return array<string, mixed>
     */
    private function runImport(Tenant $tenant, string $csv, string $mode, string $matchField, bool $preserveEmpty = true, ?array $mapping = null): array
    {
        Storage::fake('local');
        $path = "contact-imports/{$tenant->id}/import.csv";
        Storage::disk('local')->put($path, $csv);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $import = ProductImport::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'resource' => 'contacts', 'requested_by' => $user->id,
            'original_filename' => 'import.csv', 'file_path' => $path, 'status' => 'queued',
            'mode' => $mode, 'match_field' => $matchField, 'preserve_empty' => $preserveEmpty,
            'mapping' => $mapping ?? ['has_headers' => true, 'name' => 0, 'phone' => 1, 'email' => 2],
            'proposed_fields' => [], 'queued_at' => now(),
        ]);

        return app(ContactImportService::class)->runQueued($import);
    }
}
