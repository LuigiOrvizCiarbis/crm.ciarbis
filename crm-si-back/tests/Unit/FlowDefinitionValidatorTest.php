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
}
