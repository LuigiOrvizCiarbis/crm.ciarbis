<?php

namespace Tests\Feature\Api;

use App\Enums\ContactFieldType;
use App\Models\Contact;
use App\Models\ContactField;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Orden del listado de contactos por una columna custom (`sort_by=custom:<key>`).
 *
 * El valor vive en el JSON `custom_data`, así que el orden depende del tipo
 * declarado en ContactField: sin cast, un campo numérico ordenaría "9" después
 * de "10" y un booleano por el texto "false"/"true".
 */
class ContactCustomFieldSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_sorts_number_field_numerically_not_as_text(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'ciclos', ContactFieldType::Number);

        $two = $this->createContact($user->tenant_id, 'Dos', ['ciclos' => 2]);
        $ten = $this->createContact($user->tenant_id, 'Diez', ['ciclos' => 10]);
        $nine = $this->createContact($user->tenant_id, 'Nueve', ['ciclos' => 9]);

        $ids = $this->sortedIds('custom:ciclos', 'asc');

        $this->assertSame([$two->id, $nine->id, $ten->id], $ids);
    }

    public function test_sort_direction_is_inverted_on_desc(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'ciclos', ContactFieldType::Number);

        $two = $this->createContact($user->tenant_id, 'Dos', ['ciclos' => 2]);
        $ten = $this->createContact($user->tenant_id, 'Diez', ['ciclos' => 10]);
        $nine = $this->createContact($user->tenant_id, 'Nueve', ['ciclos' => 9]);

        $ids = $this->sortedIds('custom:ciclos', 'desc');

        $this->assertSame([$ten->id, $nine->id, $two->id], $ids);
    }

    public function test_sorts_text_field_alphabetically(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'ciudad', ContactFieldType::Text);

        $cordoba = $this->createContact($user->tenant_id, 'C', ['ciudad' => 'Cordoba']);
        $bahia = $this->createContact($user->tenant_id, 'B', ['ciudad' => 'Bahia']);
        $rosario = $this->createContact($user->tenant_id, 'R', ['ciudad' => 'Rosario']);

        $this->assertSame(
            [$bahia->id, $cordoba->id, $rosario->id],
            $this->sortedIds('custom:ciudad', 'asc')
        );
    }

    public function test_sorts_date_field_chronologically(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'vencimiento', ContactFieldType::Date);

        $late = $this->createContact($user->tenant_id, 'Tarde', ['vencimiento' => '2026-12-01']);
        $early = $this->createContact($user->tenant_id, 'Temprano', ['vencimiento' => '2026-01-15']);
        $mid = $this->createContact($user->tenant_id, 'Medio', ['vencimiento' => '2026-06-30']);

        $this->assertSame(
            [$early->id, $mid->id, $late->id],
            $this->sortedIds('custom:vencimiento', 'asc')
        );
    }

    public function test_sorts_boolean_field_by_value_not_by_text(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'activo', ContactFieldType::Boolean);

        $yes = $this->createContact($user->tenant_id, 'Si', ['activo' => true]);
        $no = $this->createContact($user->tenant_id, 'No', ['activo' => false]);

        $this->assertSame([$no->id, $yes->id], $this->sortedIds('custom:activo', 'asc'));
        $this->assertSame([$yes->id, $no->id], $this->sortedIds('custom:activo', 'desc'));
    }

    public function test_contacts_without_value_go_last_in_both_directions(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'ciclos', ContactFieldType::Number);

        $one = $this->createContact($user->tenant_id, 'Uno', ['ciclos' => 1]);
        $five = $this->createContact($user->tenant_id, 'Cinco', ['ciclos' => 5]);
        $empty = $this->createContact($user->tenant_id, 'Sin dato', []);

        $asc = $this->sortedIds('custom:ciclos', 'asc');
        $desc = $this->sortedIds('custom:ciclos', 'desc');

        $this->assertSame([$one->id, $five->id, $empty->id], $asc);
        $this->assertSame([$five->id, $one->id, $empty->id], $desc);
    }

    public function test_malformed_number_value_does_not_break_query(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'ciclos', ContactFieldType::Number);

        // Cargado fuera de la validación normal (ej. vía psql): en Postgres el
        // cast a numérico sobre este valor reventaría la consulta entera.
        $corrupt = $this->createContact($user->tenant_id, 'Corrupto', ['ciclos' => 'N/A']);
        $valid = $this->createContact($user->tenant_id, 'Valido', ['ciclos' => 3]);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'sort_by' => 'custom:ciclos',
            'sort_dir' => 'asc',
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($valid->id, $ids);
        $this->assertContains($corrupt->id, $ids);
    }

    public function test_unknown_custom_key_falls_back_to_default_sort(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $contact = $this->createContact($user->tenant_id, 'Solo', []);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'sort_by' => 'custom:campo_inexistente',
        ]));

        $response->assertOk();
        $this->assertSame([$contact->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_fallback_also_discards_the_requested_direction(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $older = $this->createContact($user->tenant_id, 'Viejo', []);
        Contact::withoutGlobalScopes()->whereKey($older->id)->update(['updated_at' => now()->subDay()]);
        $newer = $this->createContact($user->tenant_id, 'Nuevo', []);

        // El 'asc' venía de la columna que se descartó; aplicarlo igual dejaría
        // el listado con los contactos más viejos arriba.
        $response = $this->getJson('/api/contacts?'.http_build_query([
            'sort_by' => 'custom:campo_inexistente',
            'sort_dir' => 'asc',
        ]));

        $response->assertOk();
        $this->assertSame([$newer->id, $older->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_custom_key_from_another_tenant_is_not_accepted(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        // El campo existe, pero pertenece a otro tenant: para este usuario es
        // tan desconocido como uno inventado y el orden cae al de por defecto.
        $otherTenant = Tenant::create(['name' => 'Otro '.uniqid()]);
        $this->createField($otherTenant->id, 'ajeno', ContactFieldType::Number);

        // El valor del campo y la fecha van en sentidos opuestos, así que cada
        // criterio da un orden distinto y el test distingue cuál se aplicó.
        $highValue = $this->createContact($user->tenant_id, 'Valor alto', ['ajeno' => 99]);
        // Update por query: el modelo refrescaría updated_at al guardar, que es
        // justo el valor que se está fijando.
        Contact::withoutGlobalScopes()->whereKey($highValue->id)->update(['updated_at' => now()->subDay()]);
        $lowValue = $this->createContact($user->tenant_id, 'Valor bajo', ['ajeno' => 1]);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'sort_by' => 'custom:ajeno',
            'sort_dir' => 'desc',
        ]));

        $response->assertOk();
        // Por el campo, 'desc' pondría primero al valor 99; el fallback por
        // updated_at pone primero al más reciente, que es el valor 1.
        $this->assertSame([$lowValue->id, $highValue->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_native_column_sort_still_works(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $beto = $this->createContact($user->tenant_id, 'Beto', []);
        $ana = $this->createContact($user->tenant_id, 'Ana', []);

        $this->assertSame([$ana->id, $beto->id], $this->sortedIds('name', 'asc'));
    }

    /**
     * @return array<int, int>
     */
    private function sortedIds(string $sortBy, string $direction): array
    {
        $response = $this->getJson('/api/contacts?'.http_build_query([
            'sort_by' => $sortBy,
            'sort_dir' => $direction,
        ]));

        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    }

    private function createContact(int $tenantId, string $name, array $customData): Contact
    {
        return Contact::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'source' => 'manual',
            'custom_data' => $customData,
        ]);
    }

    private function createField(int $tenantId, string $key, ContactFieldType $type, array $options = []): ContactField
    {
        return ContactField::create([
            'tenant_id' => $tenantId,
            'key' => $key,
            'label' => ucfirst($key),
            'type' => $type,
            'options' => $options === [] ? null : $options,
            'display_order' => 0,
        ]);
    }

    private function createOwner(): array
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Owner');

        return [$user, $tenant];
    }

    private function seedTenantWithRoles(): Tenant
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

        return $tenant;
    }
}
