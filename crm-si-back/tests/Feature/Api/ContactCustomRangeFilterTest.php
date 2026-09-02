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

class ContactCustomRangeFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_date_field_within_inclusive_range(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $field = $this->createDateField($user->tenant_id, 'vencimiento');

        $inLowerBound = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-01']);
        $inUpperBound = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-07']);
        $inside = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-04']);
        $before = $this->createContact($user->tenant_id, ['vencimiento' => '2026-08-31']);
        $after = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-08']);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom_range' => ['vencimiento' => ['from' => '2026-09-01', 'to' => '2026-09-07']],
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($inLowerBound->id, $ids);
        $this->assertContains($inUpperBound->id, $ids);
        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($before->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_filters_with_only_from(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createDateField($user->tenant_id, 'vencimiento');

        $onOrAfter = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-10']);
        $before = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-05']);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom_range' => ['vencimiento' => ['from' => '2026-09-09']],
        ]));

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($onOrAfter->id, $ids);
        $this->assertNotContains($before->id, $ids);
    }

    public function test_filters_with_only_to(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createDateField($user->tenant_id, 'vencimiento');

        $onOrBefore = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-05']);
        $after = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-10']);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom_range' => ['vencimiento' => ['to' => '2026-09-09']],
        ]));

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($onOrBefore->id, $ids);
        $this->assertNotContains($after->id, $ids);
    }

    public function test_filters_number_field_within_inclusive_range(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'ciclos_impagos', ContactFieldType::Number);

        // El caso que un filtro textual rompería: "9" > "10" como string.
        $nine = $this->createContact($user->tenant_id, ['ciclos_impagos' => 9]);
        $ten = $this->createContact($user->tenant_id, ['ciclos_impagos' => 10]);
        $two = $this->createContact($user->tenant_id, ['ciclos_impagos' => 2]);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom_range' => ['ciclos_impagos' => ['from' => '5', 'to' => '10']],
        ]));

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($nine->id, $ids);
        $this->assertContains($ten->id, $ids);
        $this->assertNotContains($two->id, $ids);
    }

    public function test_unknown_key_is_ignored(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createDateField($user->tenant_id, 'vencimiento');
        $contact = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-04']);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom_range' => ['campo_inexistente' => ['from' => '2026-01-01']],
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($contact->id, $ids);
    }

    public function test_range_on_text_field_is_ignored(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'notas', ContactFieldType::Text);
        $contact = $this->createContact($user->tenant_id, ['notas' => 'zzz']);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom_range' => ['notas' => ['from' => 'aaa', 'to' => 'mmm']],
        ]));

        $response->assertOk();
        // El rango sobre un campo Text no whitelisteado se descarta entero:
        // el filtro no se aplica y el contacto sigue apareciendo.
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($contact->id, $ids);
    }

    public function test_malformed_number_value_does_not_break_query(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'ciclos_impagos', ContactFieldType::Number);

        // Cargado a mano fuera de la validación normal (ej. vía psql).
        $corrupt = Contact::create([
            'tenant_id' => $user->tenant_id,
            'name' => 'Corrupto',
            'source' => 'manual',
            'custom_data' => ['ciclos_impagos' => 'N/A'],
        ]);
        $valid = $this->createContact($user->tenant_id, ['ciclos_impagos' => 3]);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom_range' => ['ciclos_impagos' => ['from' => '1', 'to' => '5']],
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($valid->id, $ids);
        $this->assertNotContains($corrupt->id, $ids);
    }

    public function test_cross_tenant_isolation(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createDateField($user->tenant_id, 'vencimiento');
        $mine = $this->createContact($user->tenant_id, ['vencimiento' => '2026-09-04']);

        $otherTenant = Tenant::create(['name' => 'Otro '.uniqid()]);
        ContactField::create([
            'tenant_id' => $otherTenant->id,
            'key' => 'vencimiento',
            'label' => 'Vencimiento',
            'type' => ContactFieldType::Date,
            'display_order' => 0,
        ]);
        $theirs = $this->createContact($otherTenant->id, ['vencimiento' => '2026-09-04']);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom_range' => ['vencimiento' => ['from' => '2026-09-01', 'to' => '2026-09-07']],
        ]));

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_equality_filter_still_works_alongside_range(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'estado', ContactFieldType::Select, ['choices' => ['al_dia', 'impago']]);
        $match = $this->createContact($user->tenant_id, ['estado' => 'impago']);
        $other = $this->createContact($user->tenant_id, ['estado' => 'al_dia']);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom' => ['estado' => 'impago'],
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_multi_select_filter_still_works_alongside_range(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);
        $this->createField($user->tenant_id, 'rubros', ContactFieldType::MultiSelect, ['choices' => ['alquiler', 'venta']]);
        $match = $this->createContact($user->tenant_id, ['rubros' => ['alquiler', 'venta']]);
        $other = $this->createContact($user->tenant_id, ['rubros' => ['venta']]);

        $response = $this->getJson('/api/contacts?'.http_build_query([
            'custom' => ['rubros' => ['alquiler']],
        ]));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($match->id, $ids);
        $this->assertNotContains($other->id, $ids);
    }

    private function createContact(int $tenantId, array $customData): Contact
    {
        return Contact::create([
            'tenant_id' => $tenantId,
            'name' => 'Contacto '.uniqid(),
            'source' => 'manual',
            'custom_data' => $customData,
        ]);
    }

    private function createDateField(int $tenantId, string $key): ContactField
    {
        return $this->createField($tenantId, $key, ContactFieldType::Date);
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
