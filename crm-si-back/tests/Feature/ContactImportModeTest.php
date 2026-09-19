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
use Illuminate\Validation\ValidationException;
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
     * El caso que motivó el cambio: un CSV exportado de otra plataforma trae
     * miles de filas sin teléfono, y al identificar por teléfono el importador
     * las rechazaba todas aunque en modo `create` no pueden colisionar.
     */
    public function test_modo_create_importa_las_filas_sin_identificador(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $result = $this->runImport($tenant, "nombre,telefono,email\nAna,,ana@acme.com\nBeto,,beto@acme.com\n", 'create', 'phone');

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['errors']);
        $this->assertSame(2, $result['without_identifier']);
        $this->assertSame(2, Contact::where('tenant_id', $tenant->id)->count());
        $this->assertNull(Contact::where('tenant_id', $tenant->id)->firstWhere('name', 'Ana')->phone);
    }

    /** Sin clave no hay contra qué emparejar, así que update sí debe rechazarlas. */
    public function test_modo_update_sigue_rechazando_las_filas_sin_identificador(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana', 'phone' => '+5491122223333', 'source' => 'manual']);

        $result = $this->runImport($tenant, "nombre,telefono,email\nAna Nueva,,ana@acme.com\n", 'update', 'phone');

        $this->assertSame(1, $result['errors']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('Falta el identificador seleccionado', $result['error_rows'][0]['reason']);
    }

    /**
     * Varias filas sin identificador no deben reportarse como repetidas entre
     * sí: la clave vacía no se registra en el índice de vistos.
     */
    public function test_las_filas_sin_identificador_no_se_marcan_como_repetidas(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $result = $this->runImport($tenant, "nombre,telefono,email\nAna,,a@acme.com\nBeto,,b@acme.com\nCeci,,c@acme.com\n", 'create', 'phone');

        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['errors']);
    }

    /** Un email en la columna de teléfono es una columna mal mapeada, no un dato faltante. */
    public function test_distingue_el_identificador_vacio_del_que_se_vacia_al_normalizar(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $result = $this->runImport($tenant, "nombre,telefono,email\nAna,ana@acme.com,ana@acme.com\n", 'update', 'phone');

        $this->assertSame(1, $result['errors']);
        $this->assertStringContainsString('no tiene dígitos', $result['error_rows'][0]['reason']);
    }

    public function test_el_resultado_resume_los_errores_por_motivo(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        Contact::create(['tenant_id' => $tenant->id, 'name' => 'Ana', 'phone' => '+5491122223333', 'source' => 'manual']);

        $csv = "nombre,telefono,email\nAna,,a@acme.com\nBeto,,b@acme.com\nCeci,+5491199998888,c@acme.com\nCeci Bis,+54 9 11 9999-8888,d@acme.com\n";
        $result = $this->runImport($tenant, $csv, 'update', 'phone');

        $motivos = collect($result['error_summary'])->pluck('count', 'reason');
        $this->assertSame(2, $motivos['Falta el identificador seleccionado']);
        $this->assertSame(1, $motivos['Identificador repetido dentro del archivo']);
    }

    /** La descarga necesita todas las filas, no las primeras 50 de la tabla. */
    public function test_guarda_todas_las_filas_con_error_para_la_descarga(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $csv = "nombre,telefono,email\n";
        for ($i = 0; $i < 60; $i++) {
            $csv .= "Cliente {$i},,c{$i}@acme.com\n";
        }

        $result = $this->runImport($tenant, $csv, 'update', 'phone');

        $this->assertSame(60, $result['errors']);
        $this->assertCount(50, $result['error_rows']);
        $this->assertCount(60, $result['error_rows_all']);
        $this->assertFalse($result['error_rows_truncated']);
    }

    /**
     * "fila 166" no alcanza para encontrar el registro en un archivo de miles
     * de líneas: el reporte tiene que decir de quién es la fila.
     */
    public function test_las_filas_con_error_llevan_los_datos_del_contacto(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $csv = "nombre,telefono,email\nAna Gómez,+5491122223333,ana@acme.com\nAna Bis,+54 9 11 2222-3333,bis@acme.com\n";
        $result = $this->runImport($tenant, $csv, 'create', 'phone');

        $fila = $result['error_rows'][0];
        $this->assertSame(3, $fila['row']);
        $this->assertSame('Ana Bis', $fila['name']);
        $this->assertSame('+54 9 11 2222-3333', $fila['phone']);
        $this->assertSame('bis@acme.com', $fila['email']);
    }

    /** Sin saber contra qué fila choca, no se puede decidir cuál conservar. */
    public function test_el_duplicado_indica_la_fila_con_la_que_choca(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $csv = "nombre,telefono,email\nAna,+5491122223333,ana@acme.com\nBeto,+5491144445555,beto@acme.com\nAna Bis,+54 9 11 2222-3333,bis@acme.com\n";
        $result = $this->runImport($tenant, $csv, 'create', 'phone');

        $fila = $result['error_rows'][0];
        $this->assertSame(4, $fila['row']);
        $this->assertSame(2, $fila['conflicts_with_row']);
        $this->assertStringContainsString('ya aparece en la fila 2', $fila['reason']);
    }

    /**
     * El motivo de cada duplicado nombra una fila distinta, así que el resumen
     * tiene que agrupar por causa y no por el texto crudo.
     */
    public function test_el_resumen_agrupa_los_duplicados_pese_al_numero_de_fila(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);

        $csv = "nombre,telefono,email\nAna,+5491122223333,a@acme.com\nAna Bis,+5491122223333,b@acme.com\nBeto,+5491144445555,c@acme.com\nBeto Bis,+5491144445555,d@acme.com\n";
        $result = $this->runImport($tenant, $csv, 'create', 'phone');

        $this->assertCount(1, $result['error_summary']);
        $this->assertSame('Identificador repetido dentro del archivo', $result['error_summary'][0]['reason']);
        $this->assertSame(2, $result['error_summary'][0]['count']);
    }

    /** El CSV descargable tiene que traer las columnas que identifican la fila. */
    public function test_el_csv_de_errores_incluye_las_columnas_identificatorias(): void
    {
        [$tenant, $user] = $this->authenticatedOwner();
        $import = ProductImport::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'resource' => 'contacts', 'requested_by' => $user->id,
            'original_filename' => 'x.csv', 'file_path' => 'x.csv', 'status' => 'completed',
            'mode' => 'create', 'match_field' => 'phone', 'preserve_empty' => true,
            'mapping' => [], 'proposed_fields' => [],
            'result' => ['error_rows_all' => [[
                'row' => 166, 'reason' => 'Identificador repetido: ya aparece en la fila 12',
                'name' => 'Ana "La Jefa" Gómez', 'phone' => '+5491122223333',
                'email' => 'ana@acme.com', 'identifier' => '+5491122223333', 'conflicts_with_row' => 12,
            ]]],
        ]);

        $response = $this->get("/api/contacts/import/{$import->id}/errors");

        $response->assertOk();
        $csv = $response->getContent();
        $this->assertStringContainsString('fila,nombre,telefono,email,identificador,fila_en_conflicto,motivo', $csv);
        $this->assertStringContainsString('166,', $csv);
        // Las comillas del nombre se escapan duplicándolas, como manda el formato.
        $this->assertStringContainsString('"Ana ""La Jefa"" Gómez"', $csv);
        $this->assertStringContainsString('ana@acme.com', $csv);
        $this->assertStringContainsString(',12,', $csv);
    }

    /**
     * El nombre viene del archivo que sube el usuario y termina en un CSV que
     * otra persona abre en Excel: sin escapar, una celda que arranca con `=`
     * se ejecuta como fórmula al abrir el reporte.
     */
    public function test_el_csv_de_errores_neutraliza_las_formulas(): void
    {
        [$tenant, $user] = $this->authenticatedOwner();
        $import = ProductImport::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'resource' => 'contacts', 'requested_by' => $user->id,
            'original_filename' => 'x.csv', 'file_path' => 'x.csv', 'status' => 'completed',
            'mode' => 'create', 'match_field' => 'phone', 'preserve_empty' => true,
            'mapping' => [], 'proposed_fields' => [],
            'result' => ['error_rows_all' => [[
                'row' => 2, 'reason' => '=1+1', 'name' => '=cmd|\'/c calc\'!A1',
                'phone' => '+5491122223333', 'email' => '@SUM(A1:A9)',
                'identifier' => '-2+3', 'conflicts_with_row' => null,
            ]]],
        ]);

        $csv = $this->get("/api/contacts/import/{$import->id}/errors")->assertOk()->getContent();

        $this->assertStringContainsString('"\'=cmd|\'/c calc\'!A1"', $csv);
        $this->assertStringContainsString('"\'@SUM(A1:A9)"', $csv);
        $this->assertStringContainsString('"\'-2+3"', $csv);
        $this->assertStringContainsString('"\'=1+1"', $csv);
        // Un teléfono internacional no es evaluable: se deja sin apóstrofo para
        // no ensuciar la columna más común del reporte.
        $this->assertStringContainsString('"+5491122223333"', $csv);
        // Pero la aritmética sí se evalúa, aunque sean sólo dígitos y signos.
        $this->assertStringContainsString('"\'-2+3"', $csv);
    }

    /**
     * @param  array<string, mixed>|null  $mapping
     * @return array<string, mixed>
     */
    /**
     * Un campo eliminado conserva su fila (soft delete) y con ella su
     * (tenant_id, key) en el índice único, así que el importador lo encontraba
     * y cortaba el import con "ya existe con otro tipo" aunque el usuario ya
     * lo hubiera borrado y el tipo propuesto fuese el mismo.
     */
    public function test_un_campo_eliminado_se_restaura_en_vez_de_romper_el_import(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $field = ContactField::create([
            'tenant_id' => $tenant->id, 'key' => 'total_consumido_ars',
            'label' => 'Total consumido (ARS)', 'type' => ContactFieldType::Number,
            'is_required' => false, 'is_unique' => false, 'display_order' => 1,
        ]);
        $field->delete();

        $result = $this->runImport(
            $tenant,
            "nombre,telefono,total\nAna,+5491122223333,1500\n",
            'create',
            'phone',
            true,
            ['has_headers' => true, 'name' => 0, 'phone' => 1, 'custom' => ['proposed:nuevo' => 2]],
            [['id' => 'nuevo', 'label' => 'Total consumido (ARS)', 'type' => 'number']],
        );

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['errors']);

        $restored = ContactField::where('tenant_id', $tenant->id)->where('key', 'total_consumido_ars')->sole();
        $this->assertNull($restored->deleted_at);
        $this->assertSame(1, ContactField::withTrashed()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1500.0, (float) Contact::where('tenant_id', $tenant->id)->sole()->custom_data['total_consumido_ars']);
    }

    public function test_un_campo_eliminado_adopta_el_tipo_propuesto_al_restaurarse(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        $field = ContactField::create([
            'tenant_id' => $tenant->id, 'key' => 'total_consumido_ars',
            'label' => 'Total consumido (ARS)', 'type' => ContactFieldType::Text,
            'is_required' => false, 'is_unique' => false, 'display_order' => 1,
        ]);
        $field->delete();

        $result = $this->runImport(
            $tenant,
            "nombre,telefono,total\nAna,+5491122223333,1500\n",
            'create',
            'phone',
            true,
            ['has_headers' => true, 'name' => 0, 'phone' => 1, 'custom' => ['proposed:nuevo' => 2]],
            [['id' => 'nuevo', 'label' => 'Total consumido (ARS)', 'type' => 'number']],
        );

        $this->assertSame(1, $result['created']);
        $restored = ContactField::where('tenant_id', $tenant->id)->sole();
        $this->assertSame(ContactFieldType::Number, $restored->type);
        $this->assertNull($restored->deleted_at);
    }

    /** Un campo vivo con otro tipo sigue siendo un conflicto real. */
    public function test_un_campo_vivo_con_otro_tipo_sigue_rechazando_el_import(): void
    {
        $tenant = Tenant::create(['name' => 'Acme']);
        ContactField::create([
            'tenant_id' => $tenant->id, 'key' => 'total_consumido_ars',
            'label' => 'Total consumido (ARS)', 'type' => ContactFieldType::Text,
            'is_required' => false, 'is_unique' => false, 'display_order' => 1,
        ]);

        $this->expectException(ValidationException::class);

        $this->runImport(
            $tenant,
            "nombre,telefono,total\nAna,+5491122223333,1500\n",
            'create',
            'phone',
            true,
            ['has_headers' => true, 'name' => 0, 'phone' => 1, 'custom' => ['proposed:nuevo' => 2]],
            [['id' => 'nuevo', 'label' => 'Total consumido (ARS)', 'type' => 'number']],
        );
    }

    private function runImport(Tenant $tenant, string $csv, string $mode, string $matchField, bool $preserveEmpty = true, ?array $mapping = null, array $proposedFields = []): array
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
            'proposed_fields' => $proposedFields, 'queued_at' => now(),
        ]);

        return app(ContactImportService::class)->runQueued($import);
    }
}
