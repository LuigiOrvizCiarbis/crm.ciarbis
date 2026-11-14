<?php

namespace Tests\Unit;

use App\Enums\ContactFieldType;
use App\Models\ContactField;
use App\Models\Tenant;
use App\Services\Flows\Builder\FlowDefinitionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowDefinitionValidatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Acme']);
        ContactField::clearTenantCache();
    }

    public function test_reserved_key_is_rejected_at_its_path(): void
    {
        $errors = $this->validate([$this->screen([$this->question('date')])]);

        $this->assertArrayHasKey('screens.0.blocks.0.key', $errors);
    }

    public function test_keys_starting_with_required_are_rejected(): void
    {
        $errors = $this->validate([$this->screen([$this->question('requerido'), $this->question('required_doc')])]);

        $this->assertSame(['screens.0.blocks.1.key'], array_keys($errors));
    }

    public function test_a_question_mapped_to_a_required_crm_field_must_be_required(): void
    {
        ContactField::create([
            'tenant_id' => $this->tenant->id,
            'key' => 'dni',
            'label' => 'DNI',
            'type' => ContactFieldType::Text,
            'is_required' => true,
            'display_order' => 1,
        ]);
        $block = ['required' => false, 'maps_to' => 'custom_data.dni'] + $this->question('dni');

        $this->assertArrayHasKey('screens.0.blocks.0.required', $this->validate([$this->screen([$block])]));

        $block['required'] = true;
        $this->assertSame([], $this->validate([$this->screen([$block])]));
    }

    public function test_jumps_only_go_forward_and_every_screen_must_be_reachable(): void
    {
        $choice = [
            'type' => 'single_choice', 'key' => 'cliente', 'label' => 'Cliente', 'required' => true,
            'options' => [['id' => 'si', 'title' => 'Sí'], ['id' => 'no', 'title' => 'No']],
        ];
        $first = ['id' => 'uno'] + $this->screen([$choice + ['branches' => ['si' => 'screen:tres', 'no' => 'end']]]);
        $second = ['id' => 'dos'] + $this->screen([$this->question('nota')]);
        $third = ['id' => 'tres', 'next' => 'screen:uno'] + $this->screen([$this->question('empresa')]);

        $errors = $this->validate([$first, $second, $third]);
        $this->assertSame(['Sólo se puede saltar a una pantalla posterior.'], $errors['screens.2.next']);

        unset($third['next']);
        $this->assertSame(['screens.1' => ['Ninguna respuesta lleva a esta pantalla.']], $this->validate([$first, $second, $third]));

        $first['blocks'][0]['branches']['no'] = 'screen:dos';
        $this->assertSame([], $this->validate([$first, $second, $third]));
    }

    public function test_choice_mapped_to_a_select_field_must_use_its_choices(): void
    {
        ContactField::create([
            'tenant_id' => $this->tenant->id,
            'key' => 'plan',
            'label' => 'Plan',
            'type' => ContactFieldType::Select,
            'options' => ['choices' => ['Básico', 'Pro']],
            'display_order' => 1,
        ]);
        $block = [
            'type' => 'single_choice',
            'key' => 'plan',
            'label' => 'Plan',
            'required' => true,
            'options' => [['id' => 'basico', 'title' => 'Básico'], ['id' => 'pro', 'title' => 'Pro']],
            'maps_to' => 'custom_data.plan',
        ];

        $errors = $this->validate([$this->screen([$block])]);
        $this->assertArrayHasKey('screens.0.blocks.0.options', $errors);

        $block['options'] = [['id' => 'Básico', 'title' => 'Básico'], ['id' => 'Pro', 'title' => 'Pro']];
        $this->assertSame([], $this->validate([$this->screen([$block])]));
    }

    public function test_a_screen_admits_at_most_five_opt_ins(): void
    {
        $optIns = array_map(
            fn (int $i) => ['type' => 'opt_in', 'key' => "acepto_{$i}", 'label' => 'Acepto', 'required' => false],
            range(1, 6),
        );

        $this->assertArrayHasKey('screens.0.blocks', $this->validate([$this->screen($optIns)]));
        $this->assertSame([], $this->validate([$this->screen(array_slice($optIns, 0, 5))]));
    }

    public function test_a_flow_admits_at_most_ten_screens(): void
    {
        $screens = array_map(fn (int $i) => $this->screen([$this->question("campo_{$i}")]), range(1, 11));

        $this->assertArrayHasKey('screens', $this->validate($screens));
        $this->assertSame([], $this->validate(array_slice($screens, 0, 10)));
    }

    public function test_malformed_structures_are_reported_instead_of_failing(): void
    {
        $choice = [
            'type' => 'single_choice', 'key' => 'plan', 'label' => 'Plan', 'required' => true,
            'options' => [['id' => ['x'], 'title' => 'A']], 'branches' => ['x' => 'end'],
        ];

        $this->assertArrayHasKey('screens.0.blocks', $this->validate([['title' => 'Datos', 'footer_label' => 'Enviar', 'blocks' => 'x']]));
        $this->assertArrayHasKey('screens.0.blocks.0.options.0.id', $this->validate([$this->screen([$choice])]));
    }

    public function test_images_must_be_jpeg_or_png_within_meta_limits(): void
    {
        $png = $this->png(10, 10);
        $image = ['type' => 'image', 'src' => $png, 'alt_text' => null, 'size' => 'medium', 'scale_type' => 'contain'];
        $choice = [
            'type' => 'multiple_choice', 'key' => 'gustos', 'label' => 'Gustos', 'required' => false,
            'options' => [['id' => 'A', 'title' => 'A', 'image' => $this->png(250, 250, noise: true)]],
        ];
        $carousel = ['type' => 'image_carousel', 'aspect_ratio' => '4:3', 'scale_type' => 'contain', 'images' => [['src' => $png, 'alt_text' => '']]];

        $this->assertSame([], $this->validate([$this->screen([$image, $this->question('nombre')])]));
        $this->assertArrayHasKey('screens.0.blocks.0.src', $this->validate([$this->screen([['src' => base64_encode('<svg/>')] + $image, $this->question('nombre')])]));
        $this->assertArrayHasKey('screens.0.blocks', $this->validate([$this->screen([$image, $image, $image, $image, $this->question('nombre')])]));
        // Meta admite hasta 100 KB por imagen de opción y exige describir las del carrusel.
        $this->assertArrayHasKey('screens.0.blocks.0.options.0.image', $this->validate([$this->screen([$choice])]));
        $this->assertArrayHasKey('screens.0.blocks.0.images.0.alt_text', $this->validate([$this->screen([$carousel, $this->question('nombre')])]));
    }

    public function test_a_repeater_group_must_match_its_crm_field_and_fit_in_ten_screens(): void
    {
        $this->petsField();
        $group = $this->group();

        $this->assertSame([], $this->validate([$group]));

        // La copia de los subcampos quedó vieja: el CRM agregó una especie.
        $stale = $group;
        $stale['fields'][1]['choices'] = ['Gato'];
        $this->assertArrayHasKey('screens.0.fields', $this->validate([$stale]));

        // Un campo que no es repeater no sirve.
        $this->assertArrayHasKey('screens.0.field_key', $this->validate([['field_key' => 'otro'] + $group]));

        // Más filas de las que admite el campo, o etiquetas más largas que las de Meta.
        $this->assertArrayHasKey('screens.0.max_rows', $this->validate([['max_rows' => 6] + $group]));
        $long = $group;
        $long['fields'][0]['label'] = 'Nombre de tu mascota X';
        $this->assertArrayHasKey('screens.0.fields.0.label', $this->validate([$long]));

        // 9 pantallas comunes + un grupo de 2 filas = 11 pantallas para Meta.
        $screens = array_map(fn (int $i) => ['id' => "s{$i}"] + $this->screen([$this->question("dato{$i}")]), range(1, 9));
        $this->assertArrayHasKey('screens', $this->validate([...$screens, ['max_rows' => 2] + $group]));
    }

    public function test_questions_cannot_use_the_keys_a_group_generates(): void
    {
        $this->petsField();

        $errors = $this->validate([['id' => 'datos'] + $this->screen([$this->question('mascotas_1_1')]), $this->group()]);

        $this->assertStringContainsString('mascotas_1_1', $errors['screens'][0]);
    }

    private function petsField(): void
    {
        ContactField::create([
            'tenant_id' => $this->tenant->id,
            'key' => 'mascotas',
            'label' => 'Mascotas',
            'type' => ContactFieldType::Repeater,
            'options' => ['min_items' => 0, 'max_items' => 5, 'fields' => [
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'is_required' => true],
                ['key' => 'especie', 'label' => 'Especie', 'type' => 'select', 'options' => ['choices' => ['Gato', 'Perro']]],
                ['key' => 'chip', 'label' => 'Chip', 'type' => 'text', 'is_active' => false],
            ]],
            'display_order' => 1,
        ]);
        ContactField::create([
            'tenant_id' => $this->tenant->id,
            'key' => 'otro',
            'label' => 'Otro',
            'type' => ContactFieldType::Text,
            'display_order' => 2,
        ]);
        ContactField::clearTenantCache();
    }

    private function group(): array
    {
        return [
            'type' => 'repeater', 'id' => 'mascotas', 'key' => 'mascotas', 'field_key' => 'mascotas',
            'title' => 'Tus mascotas', 'row_title' => 'Mascota {n}', 'footer_label' => 'Seguir',
            'more_label' => '¿Agregar otra mascota?', 'ask_first' => true, 'ask_first_label' => '¿Tenés mascotas?',
            'max_rows' => 3, 'min_rows' => 0,
            'fields' => [
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
                ['key' => 'especie', 'label' => 'Especie', 'type' => 'select', 'required' => false, 'choices' => ['Gato', 'Perro']],
            ],
            'next' => 'end',
        ];
    }

    private function validate(array $screens): array
    {
        return (new FlowDefinitionValidator)->validate(
            ['schema' => 1, 'screens' => $screens, 'appointment' => null],
            $this->tenant->id,
        );
    }

    private function screen(array $blocks): array
    {
        return ['title' => 'Datos', 'footer_label' => 'Enviar', 'blocks' => $blocks];
    }

    private function question(string $key): array
    {
        return ['type' => 'short_text', 'key' => $key, 'label' => 'Dato', 'input_type' => 'text', 'required' => true];
    }

    private function png(int $width, int $height, bool $noise = false): string
    {
        $image = imagecreatetruecolor($width, $height);

        // El ruido no se comprime: sirve para superar los límites de tamaño.
        for ($x = 0; $noise && $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, random_int(0, 0xFFFFFF));
            }
        }

        ob_start();
        imagepng($image);

        return base64_encode((string) ob_get_clean());
    }
}
