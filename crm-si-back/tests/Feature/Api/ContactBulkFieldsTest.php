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

class ContactBulkFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_standard_and_custom_fields_without_replacing_other_custom_data(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'city',
            'label' => 'City',
            'type' => ContactFieldType::Text,
            'display_order' => 1,
        ]);
        $first = $this->makeContact($tenant, 'First', ['city' => 'Rosario', 'keep' => 'yes']);
        $second = $this->makeContact($tenant, 'Second', ['city' => 'Córdoba']);

        $this->postJson('/api/contacts/bulk-fields', [
            'ids' => [$first->id, $second->id],
            'updates' => [
                'source' => 'whatsapp',
                'custom_data' => ['city' => 'Buenos Aires'],
            ],
        ])->assertOk()
            ->assertJsonPath('updated', 2)
            ->assertJsonPath('failed', 0);

        $this->assertSame('whatsapp', $first->fresh()->source);
        $this->assertSame('Buenos Aires', $first->fresh()->custom_data['city']);
        $this->assertSame('yes', $first->fresh()->custom_data['keep']);
        $this->assertSame('Buenos Aires', $second->fresh()->custom_data['city']);
    }

    public function test_optional_values_can_be_cleared(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'city',
            'label' => 'City',
            'type' => ContactFieldType::Text,
            'display_order' => 1,
        ]);
        $contact = $this->makeContact($tenant, 'First', ['city' => 'Rosario']);

        $this->postJson('/api/contacts/bulk-fields', [
            'ids' => [$contact->id],
            'updates' => ['phone' => null, 'custom_data' => ['city' => null]],
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->assertNull($contact->fresh()->phone);
        $this->assertNull($contact->fresh()->custom_data['city']);
    }

    public function test_unique_and_complex_custom_fields_are_rejected(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);
        $contact = $this->makeContact($tenant, 'First');

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'document',
            'label' => 'Document',
            'type' => ContactFieldType::Text,
            'is_unique' => true,
            'display_order' => 1,
        ]);

        $this->postJson('/api/contacts/bulk-fields', [
            'ids' => [$contact->id],
            'updates' => ['custom_data' => ['document' => '123']],
        ])->assertStatus(422)->assertJsonValidationErrors('updates.custom_data.document');
    }

    private function createOwner(): array
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

        return [$user, $tenant];
    }

    private function makeContact(Tenant $tenant, string $name, array $customData = []): Contact
    {
        return Contact::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'phone' => '+5491112345678',
            'source' => 'manual',
            'custom_data' => $customData,
        ]);
    }
}
