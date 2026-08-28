<?php

namespace Tests\Feature\Api;

use App\Enums\ContactFieldType;
use App\Models\ContactField;
use App\Models\User;
use App\Support\ExtractionPresetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ExtractionPresetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_the_rental_contract_fields(): void
    {
        $user = $this->admin();

        $response = $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ]);

        $response->assertOk();
        $response->assertJsonCount(10, 'created');

        $fields = ContactField::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->get();
        $this->assertCount(10, $fields);

        $monto = $fields->firstWhere('key', 'monto_alquiler');
        $this->assertSame(ContactFieldType::Number, $monto->type);

        $indice = $fields->firstWhere('key', 'indice_ajuste');
        $this->assertSame(['IPC', 'ICL', 'Casa Propia', 'Otro'], $indice->options['choices']);

        // El PDF del contrato va como campo File: su valor es un media_asset_id.
        $this->assertSame(ContactFieldType::File, $fields->firstWhere('key', 'contrato_pdf')->type);
    }

    #[Test]
    public function the_created_fields_are_editable_and_deletable_like_any_other(): void
    {
        // El preset no crea campos de sistema: el CRM sigue siendo transversal.
        $user = $this->admin();

        $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ])->assertOk();

        $field = ContactField::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('key', 'garante')
            ->firstOrFail();

        $this->actingAs($user)
            ->putJson("/api/contact-fields/{$field->id}", ['label' => 'Garante propietario'])
            ->assertOk();

        $this->actingAs($user)
            ->deleteJson("/api/contact-fields/{$field->id}")
            ->assertOk();
    }

    #[Test]
    public function applying_it_twice_does_not_duplicate_fields(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ])->assertOk();

        $second = $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ]);

        $second->assertOk();
        $second->assertJsonCount(0, 'created');
        $second->assertJsonCount(10, 'existing');

        $this->assertSame(
            10,
            ContactField::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->count(),
        );
    }

    #[Test]
    public function it_does_not_overwrite_a_field_the_tenant_edited(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ])->assertOk();

        $field = ContactField::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('key', 'moneda')
            ->firstOrFail();
        $field->update(['label' => 'Divisa', 'options' => ['choices' => ['ARS', 'USD', 'EUR']]]);

        $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ])->assertOk();

        $field->refresh();
        $this->assertSame('Divisa', $field->label);
        $this->assertSame(['ARS', 'USD', 'EUR'], $field->options['choices']);
    }

    #[Test]
    public function it_does_not_resurrect_a_field_the_tenant_deleted(): void
    {
        // Quitar "garante" es una decisión del tenant: reaplicar el preset no
        // debería traerlo de vuelta.
        $user = $this->admin();

        $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ])->assertOk();

        ContactField::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('key', 'garante')
            ->firstOrFail()
            ->delete();

        $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ])->assertOk();

        // withoutGlobalScopes() también quita el scope de SoftDeletes, así que
        // acá se filtra explícitamente por las filas vivas.
        $this->assertFalse(
            ContactField::withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('key', 'garante')
                ->whereNull('deleted_at')
                ->exists(),
        );
    }

    #[Test]
    public function it_appends_after_the_existing_fields(): void
    {
        $user = $this->admin();

        ContactField::create([
            'tenant_id' => $user->tenant_id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => ContactFieldType::Text,
            'display_order' => 0,
        ]);

        $this->actingAs($user)->postJson('/api/contact-fields/apply-preset', [
            'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
        ])->assertOk();

        $first = ContactField::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->orderBy('display_order')
            ->first();

        $this->assertSame('dni', $first->key);
    }

    #[Test]
    public function it_rejects_an_unknown_preset(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/contact-fields/apply-preset', ['preset' => 'inexistente'])
            ->assertStatus(422);
    }

    #[Test]
    public function it_requires_the_manage_permission(): void
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole('Member');

        $this->actingAs($user)
            ->postJson('/api/contact-fields/apply-preset', [
                'preset' => ExtractionPresetRegistry::RENTAL_CONTRACT,
            ])
            ->assertForbidden();
    }

    private function admin(): User
    {
        $tenant = $this->createTenantWithRoles();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole('Admin');

        return $user;
    }
}
