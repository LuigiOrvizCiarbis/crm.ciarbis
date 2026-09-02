<?php

namespace Tests\Feature\Api;

use App\Models\Contact;
use App\Models\ContactField;
use App\Models\MediaAsset;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\RoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Enums\ContactFieldType;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContactFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_text_field(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/contact-fields', [
            'label' => 'Talle',
            'type' => 'text',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.key', 'talle')
            ->assertJsonPath('data.label', 'Talle')
            ->assertJsonPath('data.type', 'text');

        $this->assertDatabaseHas('contact_fields', ['key' => 'talle', 'label' => 'Talle']);
    }

    public function test_owner_can_create_and_validate_repeater_field(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/contact-fields', [
            'label' => 'Productos',
            'type' => 'repeater',
            'options' => [
                'fields' => [
                    ['label' => 'Producto', 'type' => 'text', 'is_required' => true],
                    ['label' => 'Cantidad', 'type' => 'number'],
                ],
                'min_items' => 1,
                'max_items' => 3,
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.type', 'repeater');
        $field = ContactField::where('key', 'productos')->firstOrFail();
        $this->assertSame('producto', $field->options['fields'][0]['key']);

        $this->postJson('/api/contacts', [
            'name' => 'Sin filas',
            'custom_data' => ['productos' => []],
        ])->assertStatus(422);

        $this->postJson('/api/contacts', [
            'name' => 'Con filas',
            'custom_data' => ['productos' => [['producto' => 'Remera', 'cantidad' => 2]]],
        ])->assertCreated();
    }

    public function test_new_keyless_repeater_subfield_cannot_reuse_omitted_key(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/contact-fields', [
            'label' => 'Productos',
            'type' => 'repeater',
            'options' => ['fields' => [['label' => 'Producto', 'type' => 'text']]],
        ])->assertCreated();

        $id = $created->json('data.id');
        $updated = $this->putJson("/api/contact-fields/{$id}", [
            'options' => ['fields' => [['label' => 'Producto', 'type' => 'number']]],
        ])->assertOk();

        $fields = $updated->json('data.options.fields');
        $this->assertSame('producto_2', $fields[0]['key']);
        $this->assertSame('producto', $fields[1]['key']);
        $this->assertFalse($fields[1]['is_active']);
    }

    public function test_required_repeater_cannot_have_zero_max_items(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/contact-fields', [
            'label' => 'Items inválidos',
            'type' => 'repeater',
            'is_required' => true,
            'options' => [
                'fields' => [['label' => 'Item', 'type' => 'text']],
                'max_items' => 0,
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('options.max_items');

        $created = $this->postJson('/api/contact-fields', [
            'label' => 'Items válidos',
            'type' => 'repeater',
            'options' => [
                'fields' => [['label' => 'Item', 'type' => 'text']],
                'max_items' => 1,
            ],
        ])->assertCreated();

        $this->putJson('/api/contact-fields/'.$created->json('data.id'), [
            'is_required' => true,
            'options' => [
                'fields' => $created->json('data.options.fields'),
                'max_items' => 0,
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('options.max_items');
    }

    public function test_repeater_rejects_unique_on_partial_update(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $created = $this->postJson('/api/contact-fields', [
            'label' => 'Items únicos',
            'type' => 'repeater',
            'options' => ['fields' => [['label' => 'Item', 'type' => 'text']]],
        ])->assertCreated();

        $this->putJson('/api/contact-fields/'.$created->json('data.id'), [
            'is_unique' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('is_unique');

        $this->assertDatabaseHas('contact_fields', [
            'id' => $created->json('data.id'),
            'is_unique' => false,
        ]);
    }

    public function test_member_cannot_create_field(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $member = User::factory()->create(['tenant_id' => $tenant->id]);
        $member->assignRole('Member');
        Sanctum::actingAs($member);

        $this->postJson('/api/contact-fields', [
            'label' => 'Talle',
            'type' => 'text',
        ])->assertForbidden();
    }

    public function test_select_requires_options(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/contact-fields', [
            'label' => 'Tamaño',
            'type' => 'select',
        ])->assertStatus(422)->assertJsonValidationErrors('options.choices');
    }

    public function test_reserved_key_gets_suffixed(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/contact-fields', [
            'label' => 'Email',
            'type' => 'text',
        ]);

        $response->assertCreated();
        $this->assertNotSame('email', $response->json('data.key'));
        $this->assertStringStartsWith('email_', $response->json('data.key'));
    }

    public function test_duplicate_label_gets_suffix(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/contact-fields', ['label' => 'Notas', 'type' => 'text']);
        $second = $this->postJson('/api/contact-fields', ['label' => 'Notas', 'type' => 'text']);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame('notas', $first->json('data.key'));
        $this->assertSame('notas_2', $second->json('data.key'));
    }

    public function test_cannot_change_type_after_create(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        $field = ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'foo',
            'label' => 'Foo',
            'type' => 'text',
        ]);

        $this->putJson("/api/contact-fields/{$field->id}", [
            'type' => 'number',
        ])->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_soft_delete_preserves_contact_values(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        $field = ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'talle',
            'label' => 'Talle',
            'type' => 'text',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Juan',
            'source' => 'manual',
            'custom_data' => ['talle' => 'L'],
        ]);

        $this->deleteJson("/api/contact-fields/{$field->id}")->assertOk();

        $this->assertSoftDeleted('contact_fields', ['id' => $field->id]);
        $this->assertSame('L', $contact->fresh()->custom_data['talle']);
    }

    public function test_create_contact_validates_custom_data_per_type(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'edad',
            'label' => 'Edad',
            'type' => 'number',
        ]);

        $this->postJson('/api/contacts', [
            'name' => 'Juan',
            'custom_data' => ['edad' => 'abc'],
        ])->assertStatus(422);

        $this->postJson('/api/contacts', [
            'name' => 'Maria',
            'custom_data' => ['edad' => 32],
        ])->assertCreated();
    }

    public function test_file_field_accepts_own_asset_and_rejects_foreign(): void
    {
        [$user, $tenant] = $this->createOwner();
        [, $otherTenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create(['tenant_id' => $tenant->id, 'key' => 'factura', 'label' => 'Factura', 'type' => 'file']);
        $own = MediaAsset::create(['tenant_id' => $tenant->id, 'name' => 'a.pdf', 'path' => 'x/a.pdf', 'mime_type' => 'application/pdf', 'size' => 10]);
        $foreign = MediaAsset::create(['tenant_id' => $otherTenant->id, 'name' => 'b.pdf', 'path' => 'y/b.pdf', 'mime_type' => 'application/pdf', 'size' => 10]);

        $this->postJson('/api/contacts', ['name' => 'Con factura', 'custom_data' => ['factura' => $own->id]])
            ->assertCreated();

        $this->postJson('/api/contacts', ['name' => 'Factura ajena', 'custom_data' => ['factura' => $foreign->id]])
            ->assertStatus(422);

        $this->postJson('/api/contacts', ['name' => 'Factura inexistente', 'custom_data' => ['factura' => 999999]])
            ->assertStatus(422);
    }

    public function test_required_custom_field_is_enforced(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => 'text',
            'is_required' => true,
        ]);

        $this->postJson('/api/contacts', [
            'name' => 'Sin DNI',
        ])->assertStatus(422);
    }

    public function test_owner_can_update_contact_through_real_middleware_pipeline(): void
    {
        [$user, $tenant] = $this->createOwner();

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Sin nombre',
            'source' => 'manual',
            'custom_data' => [],
        ]);

        $token = $user->createToken('test')->plainTextToken;

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/contacts/{$contact->id}", [
            'name' => 'Nombre Editado',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Nombre Editado');
    }

    public function test_partial_update_does_not_enforce_unrelated_required_field(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => 'text',
            'is_required' => true,
        ]);
        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'empresa',
            'label' => 'Empresa',
            'type' => 'text',
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Juan',
            'source' => 'manual',
            'custom_data' => [],
        ]);

        $this->putJson("/api/contacts/{$contact->id}", [
            'custom_data' => ['empresa' => 'ACME'],
        ])->assertOk()
            ->assertJsonPath('data.custom_data.empresa', 'ACME');

        $this->assertSame('ACME', $contact->fresh()->custom_data['empresa']);
    }

    public function test_update_of_standard_field_only_does_not_enforce_required_custom_field(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => 'text',
            'is_required' => true,
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Juan',
            'source' => 'manual',
            'custom_data' => [],
        ]);

        $this->putJson("/api/contacts/{$contact->id}", [
            'name' => 'Juan Pérez',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Juan Pérez');

        $this->assertSame('Juan Pérez', $contact->fresh()->name);
    }

    public function test_partial_update_still_enforces_required_field_when_cleared(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => 'text',
            'is_required' => true,
        ]);

        $contact = Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Juan',
            'source' => 'manual',
            'custom_data' => ['dni' => '123'],
        ]);

        $this->putJson("/api/contacts/{$contact->id}", [
            'custom_data' => ['dni' => ''],
        ])->assertStatus(422);
    }

    public function test_unique_custom_field_is_enforced(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => 'text',
            'is_unique' => true,
        ]);

        $this->postJson('/api/contacts', ['name' => 'A', 'custom_data' => ['dni' => '123']])->assertCreated();
        $this->postJson('/api/contacts', ['name' => 'B', 'custom_data' => ['dni' => '123']])->assertStatus(422);
    }

    public function test_filter_by_custom_field(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'ciudad',
            'label' => 'Ciudad',
            'type' => 'text',
        ]);

        Contact::create(['tenant_id' => $tenant->id, 'name' => 'A', 'source' => 'manual', 'custom_data' => ['ciudad' => 'CABA']]);
        Contact::create(['tenant_id' => $tenant->id, 'name' => 'B', 'source' => 'manual', 'custom_data' => ['ciudad' => 'Rosario']]);

        $response = $this->getJson('/api/contacts?custom[ciudad]=CABA');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A', $response->json('data.0.name'));
    }

    public function test_unknown_custom_keys_ignored_on_filter(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        Contact::create(['tenant_id' => $tenant->id, 'name' => 'A', 'source' => 'manual']);

        $this->getJson('/api/contacts?custom[unknown]=x')->assertOk();
    }

    public function test_unknown_custom_keys_ignored_on_validation(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/contacts', [
            'name' => 'Juan',
            'custom_data' => ['ghost_key' => 'whatever'],
        ])->assertCreated();
    }

    public function test_tenant_isolation_on_fields(): void
    {
        [$userA, $tenantA] = $this->createOwner();
        $tenantB = $this->seedTenantWithRoles();
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);
        $userB->assignRole('Owner');

        Sanctum::actingAs($userA);
        $this->postJson('/api/contact-fields', ['label' => 'Field A', 'type' => 'text'])->assertCreated();

        Sanctum::actingAs($userB);
        $list = $this->getJson('/api/contact-fields');
        $list->assertOk();
        $this->assertCount(0, $list->json('data'));
    }

    public function test_owner_can_create_currency_field_with_a_currency(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/contact-fields', [
            'label' => 'Alquiler mensual',
            'type' => 'currency',
            'options' => ['currency' => 'USD'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'currency')
            ->assertJsonPath('data.options.currency', 'USD');

        $this->postJson('/api/contacts', [
            'name' => 'Inquilino',
            'custom_data' => ['alquiler_mensual' => 1250000.5],
        ])->assertCreated();
    }

    public function test_currency_field_without_options_falls_back_to_the_default(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/contact-fields', [
            'label' => 'Expensas',
            'type' => 'currency',
        ])->assertCreated()->assertJsonPath('data.options.currency', ContactFieldType::DEFAULT_CURRENCY);
    }

    public function test_currency_field_rejects_an_unsupported_currency(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/contact-fields', [
            'label' => 'Alquiler',
            'type' => 'currency',
            'options' => ['currency' => 'XYZ'],
        ])->assertStatus(422)->assertJsonValidationErrors('options.currency');
    }

    public function test_currency_field_rejects_a_non_numeric_value(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'alquiler',
            'label' => 'Alquiler',
            'type' => 'currency',
            'options' => ['currency' => 'ARS'],
        ]);

        $this->postJson('/api/contacts', [
            'name' => 'Inquilino',
            'custom_data' => ['alquiler' => 'mil pesos'],
        ])->assertStatus(422);
    }

    public function test_currency_can_be_a_repeater_subfield(): void
    {
        [$user] = $this->createOwner();
        Sanctum::actingAs($user);

        $this->postJson('/api/contact-fields', [
            'label' => 'Unidades',
            'type' => 'repeater',
            'options' => [
                'fields' => [
                    ['label' => 'Unidad', 'type' => 'text', 'is_required' => true],
                    ['label' => 'Precio', 'type' => 'currency', 'options' => ['currency' => 'USD']],
                ],
                'min_items' => 1,
                'max_items' => 5,
            ],
        ])->assertCreated();

        $field = ContactField::where('key', 'unidades')->firstOrFail();
        $this->assertSame('USD', $field->options['fields'][1]['options']['currency']);

        $this->postJson('/api/contacts', [
            'name' => 'Edificio',
            'custom_data' => ['unidades' => [['unidad' => '3B', 'precio' => 180000]]],
        ])->assertCreated();

        $this->postJson('/api/contacts', [
            'name' => 'Otro',
            'custom_data' => ['unidades' => [['unidad' => '4A', 'precio' => 'carísimo']]],
        ])->assertStatus(422);
    }

    public function test_import_parses_currency_written_the_way_people_type_it(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'alquiler',
            'label' => 'Alquiler',
            'type' => 'currency',
            'options' => ['currency' => 'ARS'],
        ]);

        // Un CSV real trae el importe formateado como se lo ve en pantalla.
        $csv = "name,phone,alquiler\nJuan,+5491111,\"$ 1.250.000,50\"\nMaria,+5492222,900000\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $this->postJson('/api/contacts/import', [
            'file' => $file,
            'mapping' => json_encode([
                'name' => 0,
                'phone' => 1,
                'custom' => ['alquiler' => 2],
            ]),
        ])->assertOk()->assertJsonPath('data.imported', 2);

        $this->assertEquals(1250000.5, Contact::where('name', 'Juan')->first()->custom_data['alquiler']);
        $this->assertEquals(900000, Contact::where('name', 'Maria')->first()->custom_data['alquiler']);
    }

    public function test_import_csv_with_custom_field(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'talle',
            'label' => 'Talle',
            'type' => 'select',
            'options' => ['choices' => ['S', 'M', 'L']],
        ]);

        $csv = "name,phone,talle\nJuan,+5491111,L\nMaria,+5492222,M\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->postJson('/api/contacts/import', [
            'file' => $file,
            'mapping' => json_encode([
                'name' => 0,
                'phone' => 1,
                'custom' => ['talle' => 2],
            ]),
        ]);

        $response->assertOk()->assertJsonPath('data.imported', 2);

        $juan = Contact::where('name', 'Juan')->first();
        $this->assertSame('L', $juan->custom_data['talle']);
    }

    public function test_import_csv_invalid_custom_value_marks_row_as_error(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'talle',
            'label' => 'Talle',
            'type' => 'select',
            'options' => ['choices' => ['S', 'M', 'L']],
        ]);

        $csv = "name,phone,talle\nJuan,+5491111,XXL\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->postJson('/api/contacts/import', [
            'file' => $file,
            'mapping' => json_encode([
                'name' => 0,
                'phone' => 1,
                'custom' => ['talle' => 2],
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.errors', 1);
    }

    public function test_import_rejects_rows_missing_required_unmapped_custom_field(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => 'text',
            'is_required' => true,
        ]);

        $csv = "name,phone\nJuan,+5491111\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->postJson('/api/contacts/import', [
            'file' => $file,
            'mapping' => json_encode([
                'name' => 0,
                'phone' => 1,
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.errors', 1);
    }

    public function test_import_rejects_unique_custom_field_collision_with_existing_contact(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => 'text',
            'is_unique' => true,
        ]);

        Contact::create([
            'tenant_id' => $tenant->id,
            'name' => 'Existing',
            'source' => 'manual',
            'custom_data' => ['dni' => '123'],
        ]);

        $csv = "name,phone,dni\nNuevo,+5491111,123\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->postJson('/api/contacts/import', [
            'file' => $file,
            'mapping' => json_encode([
                'name' => 0,
                'phone' => 1,
                'custom' => ['dni' => 2],
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.imported', 0)
            ->assertJsonPath('data.errors', 1);
    }

    public function test_import_rejects_unique_custom_field_collision_within_batch(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => 'text',
            'is_unique' => true,
        ]);

        $csv = "name,phone,dni\nA,+5491111,123\nB,+5492222,123\n";
        $file = UploadedFile::fake()->createWithContent('contacts.csv', $csv);

        $response = $this->postJson('/api/contacts/import', [
            'file' => $file,
            'mapping' => json_encode([
                'name' => 0,
                'phone' => 1,
                'custom' => ['dni' => 2],
            ]),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.errors', 1);
    }

    public function test_manage_permission_alone_can_list_fields(): void
    {
        $tenant = $this->seedTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->assignRole('Member');
        $user->givePermissionTo('contact_fields.manage');
        Sanctum::actingAs($user);

        $this->getJson('/api/contact-fields')->assertOk();
    }

    public function test_reorder_fields(): void
    {
        [$user, $tenant] = $this->createOwner();
        Sanctum::actingAs($user);

        $a = ContactField::create(['tenant_id' => $tenant->id, 'key' => 'a', 'label' => 'A', 'type' => 'text', 'display_order' => 1]);
        $b = ContactField::create(['tenant_id' => $tenant->id, 'key' => 'b', 'label' => 'B', 'type' => 'text', 'display_order' => 2]);

        $this->postJson('/api/contact-fields/reorder', [
            'items' => [
                ['id' => $a->id, 'display_order' => 2],
                ['id' => $b->id, 'display_order' => 1],
            ],
        ])->assertOk();

        $this->assertSame(2, $a->fresh()->display_order);
        $this->assertSame(1, $b->fresh()->display_order);
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
