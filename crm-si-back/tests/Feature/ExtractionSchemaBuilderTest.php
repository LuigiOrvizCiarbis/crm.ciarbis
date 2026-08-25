<?php

namespace Tests\Feature;

use App\Enums\ContactFieldType;
use App\Models\ContactField;
use App\Support\ExtractionSchemaBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExtractionSchemaBuilderTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_field_type_declares_null_as_an_allowed_type(): void
    {
        // El schema viaja como input de una tool con strict: si un tipo no
        // declara null, el modelo no puede decir "este dato no está" y se ve
        // empujado a inventar un valor.
        $tenant = $this->createTenantWithRoles();

        $expected = [
            [ContactFieldType::Text, 'string'],
            [ContactFieldType::Number, 'number'],
            [ContactFieldType::Date, 'string'],
            [ContactFieldType::Boolean, 'boolean'],
            [ContactFieldType::Email, 'string'],
            [ContactFieldType::Url, 'string'],
            [ContactFieldType::Phone, 'string'],
        ];

        foreach ($expected as $index => [$type, $jsonType]) {
            ContactField::create([
                'tenant_id' => $tenant->id,
                'key' => 'campo_'.$type->value,
                'label' => 'Campo '.$type->value,
                'type' => $type,
                'display_order' => $index,
            ]);
        }

        $properties = app(ExtractionSchemaBuilder::class)->build($tenant->id)['schema']['properties'];

        foreach ($expected as [$type, $jsonType]) {
            $property = $properties['campo_'.$type->value];
            $this->assertSame([$jsonType, 'null'], $property['type'], "Falló el tipo {$type->value}");
        }
    }

    #[Test]
    public function select_exposes_its_choices_plus_null(): void
    {
        $tenant = $this->createTenantWithRoles();

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'indice_ajuste',
            'label' => 'Índice de ajuste',
            'type' => ContactFieldType::Select,
            'options' => ['choices' => ['IPC', 'ICL', 'Casa Propia']],
        ]);

        $property = app(ExtractionSchemaBuilder::class)->build($tenant->id)['schema']['properties']['indice_ajuste'];

        // anyOf y no type union + enum: con strict, la API rechaza un enum cuyos
        // valores no matcheen el tipo declarado ("'ARS' does not match
        // '['string','null']'"). Verificado contra la API real.
        $this->assertSame(
            [
                ['type' => 'string', 'enum' => ['IPC', 'ICL', 'Casa Propia']],
                ['type' => 'null'],
            ],
            $property['anyOf'],
        );
        $this->assertArrayNotHasKey('type', $property);
    }

    #[Test]
    public function no_property_declares_a_format_keyword(): void
    {
        // Con strict, declarar format empuja al modelo a producir un string con
        // esa forma aunque el dato no esté en el documento: una fecha ausente
        // volvía como "5210-01-01" en vez de null. Es la peor falla posible acá
        // — un dato inventado que parece válido en un campo legal.
        $tenant = $this->createTenantWithRoles();

        foreach ([ContactFieldType::Date, ContactFieldType::Email, ContactFieldType::Url] as $index => $type) {
            ContactField::create([
                'tenant_id' => $tenant->id,
                'key' => 'campo_'.$type->value,
                'label' => 'Campo '.$type->value,
                'type' => $type,
                'display_order' => $index,
            ]);
        }

        $properties = app(ExtractionSchemaBuilder::class)->build($tenant->id)['schema']['properties'];

        foreach ($properties as $key => $property) {
            $this->assertArrayNotHasKey('format', $property, "El campo {$key} declara format");
        }

        // El formato esperado se sigue pidiendo, pero por descripción: sugiere
        // sin forzar.
        $this->assertStringContainsString('AAAA-MM-DD', $properties['campo_date']['description']);
    }

    #[Test]
    public function multi_select_restricts_items_to_its_choices(): void
    {
        $tenant = $this->createTenantWithRoles();

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'servicios',
            'label' => 'Servicios incluidos',
            'type' => ContactFieldType::MultiSelect,
            'options' => ['choices' => ['Agua', 'Gas', 'Expensas']],
        ]);

        $property = app(ExtractionSchemaBuilder::class)->build($tenant->id)['schema']['properties']['servicios'];

        $this->assertSame(['array', 'null'], $property['type']);
        $this->assertSame(['Agua', 'Gas', 'Expensas'], $property['items']['enum']);
    }

    #[Test]
    public function file_fields_are_excluded_from_the_schema(): void
    {
        // El valor de un File es el id de un MediaAsset del tenant: el modelo no
        // puede conocerlo, y cualquier número que devolviera sería un id ajeno.
        $tenant = $this->createTenantWithRoles();

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'contrato_pdf',
            'label' => 'Contrato (PDF)',
            'type' => ContactFieldType::File,
        ]);
        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'direccion',
            'label' => 'Dirección',
            'type' => ContactFieldType::Text,
        ]);

        $result = app(ExtractionSchemaBuilder::class)->build($tenant->id);

        $this->assertArrayNotHasKey('contrato_pdf', $result['schema']['properties']);
        $this->assertArrayNotHasKey('contrato_pdf', $result['fields']);
        $this->assertArrayHasKey('direccion', $result['schema']['properties']);
    }

    #[Test]
    public function the_schema_is_closed_and_requires_every_property(): void
    {
        $tenant = $this->createTenantWithRoles();

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'monto',
            'label' => 'Monto del alquiler',
            'type' => ContactFieldType::Number,
        ]);

        $schema = app(ExtractionSchemaBuilder::class)->build($tenant->id)['schema'];

        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['monto'], $schema['required']);
    }

    #[Test]
    public function it_only_includes_fields_of_the_given_tenant(): void
    {
        $tenant = $this->createTenantWithRoles('Acme');
        $other = $this->createTenantWithRoles('Otro');

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'propio',
            'label' => 'Propio',
            'type' => ContactFieldType::Text,
        ]);
        ContactField::create([
            'tenant_id' => $other->id,
            'key' => 'ajeno',
            'label' => 'Ajeno',
            'type' => ContactFieldType::Text,
        ]);

        $result = app(ExtractionSchemaBuilder::class)->build($tenant->id);

        $this->assertArrayHasKey('propio', $result['schema']['properties']);
        $this->assertArrayNotHasKey('ajeno', $result['schema']['properties']);
    }

    #[Test]
    public function the_field_snapshot_records_key_and_type(): void
    {
        // El snapshot es lo que permite descartar una clave si el tenant borra
        // el campo mientras el usuario revisa la extracción.
        $tenant = $this->createTenantWithRoles();

        ContactField::create([
            'tenant_id' => $tenant->id,
            'key' => 'monto',
            'label' => 'Monto',
            'type' => ContactFieldType::Number,
        ]);

        $result = app(ExtractionSchemaBuilder::class)->build($tenant->id);

        $this->assertSame(['monto' => 'number'], $result['fields']);
    }
}
