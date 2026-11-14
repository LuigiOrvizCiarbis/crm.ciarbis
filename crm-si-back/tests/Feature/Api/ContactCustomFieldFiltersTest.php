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

class ContactCustomFieldFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->seedTenantWithRoles();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->user->assignRole('Owner');
        Sanctum::actingAs($this->user);
    }

    public function test_select_matches_any_of_the_given_choices(): void
    {
        $this->createField('plan', ContactFieldType::Select, ['choices' => ['free', 'pro', 'enterprise']]);
        $pro = $this->createContact(['plan' => 'pro']);
        $enterprise = $this->createContact(['plan' => 'enterprise']);
        $free = $this->createContact(['plan' => 'free']);
        $empty = $this->createContact([]);

        $ids = $this->filteredIds(['custom_any' => ['plan' => ['pro', 'enterprise']]]);

        $this->assertEqualsCanonicalizing([$pro->id, $enterprise->id], $ids);
        $this->assertNotContains($free->id, $ids);
        $this->assertNotContains($empty->id, $ids);
    }

    public function test_multi_select_matches_contacts_containing_any_choice(): void
    {
        $this->createField('rubros', ContactFieldType::MultiSelect, ['choices' => ['alquiler', 'venta', 'tasacion']]);
        $alquiler = $this->createContact(['rubros' => ['alquiler']]);
        $both = $this->createContact(['rubros' => ['venta', 'tasacion']]);
        $other = $this->createContact(['rubros' => ['tasacion']]);

        $ids = $this->filteredIds(['custom_any' => ['rubros' => ['alquiler', 'venta']]]);

        $this->assertEqualsCanonicalizing([$alquiler->id, $both->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_text_contains_is_case_insensitive(): void
    {
        $this->createField('notas', ContactFieldType::Text);
        $match = $this->createContact(['notas' => 'Pidió PAGO en cuotas']);
        $other = $this->createContact(['notas' => 'Sin novedades']);

        $ids = $this->filteredIds(['custom_contains' => ['notas' => 'pago']]);

        $this->assertSame([$match->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_contains_on_non_text_field_is_ignored(): void
    {
        $this->createField('plan', ContactFieldType::Select, ['choices' => ['pro']]);
        $contact = $this->createContact(['plan' => 'pro']);
        $other = $this->createContact([]);

        $ids = $this->filteredIds(['custom_contains' => ['plan' => 'zzz']]);

        $this->assertEqualsCanonicalizing([$contact->id, $other->id], $ids);
    }

    public function test_boolean_true_only_matches_true(): void
    {
        $this->createField('facturado', ContactFieldType::Boolean);
        $yes = $this->createContact(['facturado' => true]);
        $no = $this->createContact(['facturado' => false]);
        $unset = $this->createContact([]);

        $ids = $this->filteredIds(['custom' => ['facturado' => 'true']]);

        $this->assertSame([$yes->id], $ids);
        $this->assertNotContains($no->id, $ids);
        $this->assertNotContains($unset->id, $ids);
    }

    public function test_boolean_false_includes_contacts_without_the_field(): void
    {
        $this->createField('facturado', ContactFieldType::Boolean);
        $yes = $this->createContact(['facturado' => true]);
        $no = $this->createContact(['facturado' => false]);
        $unset = $this->createContact([]);

        $ids = $this->filteredIds(['custom' => ['facturado' => 'false']]);

        $this->assertEqualsCanonicalizing([$no->id, $unset->id], $ids);
        $this->assertNotContains($yes->id, $ids);
    }

    public function test_currency_range_compares_numerically(): void
    {
        $this->createField('deuda', ContactFieldType::Currency, ['currency' => 'ARS']);
        // Como texto, "9" quedaría fuera de 5..10 por ser mayor que "10".
        $nine = $this->createContact(['deuda' => 9]);
        $ten = $this->createContact(['deuda' => 10]);
        $two = $this->createContact(['deuda' => 2]);

        $ids = $this->filteredIds(['custom_range' => ['deuda' => ['from' => '5', 'to' => '10']]]);

        $this->assertEqualsCanonicalizing([$nine->id, $ten->id], $ids);
        $this->assertNotContains($two->id, $ids);
    }

    public function test_filters_on_different_fields_combine_with_and(): void
    {
        $this->createField('plan', ContactFieldType::Select, ['choices' => ['free', 'pro']]);
        $this->createField('facturado', ContactFieldType::Boolean);
        $match = $this->createContact(['plan' => 'pro', 'facturado' => false]);
        $billed = $this->createContact(['plan' => 'pro', 'facturado' => true]);
        $free = $this->createContact(['plan' => 'free']);

        $ids = $this->filteredIds([
            'custom_any' => ['plan' => ['pro']],
            'custom' => ['facturado' => 'false'],
        ]);

        $this->assertSame([$match->id], $ids);
        $this->assertNotContains($billed->id, $ids);
        $this->assertNotContains($free->id, $ids);
    }

    public function test_unknown_key_is_ignored(): void
    {
        $contact = $this->createContact([]);

        $ids = $this->filteredIds(['custom_any' => ['inexistente' => ['x']], 'custom_contains' => ['otro' => 'y']]);

        $this->assertSame([$contact->id], $ids);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<int>
     */
    private function filteredIds(array $query): array
    {
        $response = $this->getJson('/api/contacts?'.http_build_query($query));
        $response->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    }

    private function createContact(array $customData): Contact
    {
        return Contact::create([
            'tenant_id' => $this->user->tenant_id,
            'name' => 'Contacto '.uniqid(),
            'source' => 'manual',
            'custom_data' => $customData,
        ]);
    }

    private function createField(string $key, ContactFieldType $type, array $options = []): ContactField
    {
        return ContactField::create([
            'tenant_id' => $this->user->tenant_id,
            'key' => $key,
            'label' => ucfirst($key),
            'type' => $type,
            'options' => $options === [] ? null : $options,
            'display_order' => 0,
        ]);
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
