<?php

namespace Tests\Unit;

use App\Services\Flows\Builder\FlowDefinitionCompiler;
use App\Services\Flows\Builder\FlowDefinitionExpander;
use PHPUnit\Framework\TestCase;

/**
 * Un grupo repetible se convierte en una pantalla por fila con "¿Agregar
 * otro?": los caminos y las claves que genera son el contrato con el
 * endpoint y con lo que se guarda en el contacto.
 */
class FlowDefinitionExpanderTest extends TestCase
{
    public function test_each_row_becomes_a_screen_and_only_rows_past_the_minimum_ask_for_another(): void
    {
        $screens = FlowDefinitionExpander::expand($this->definition($this->group(minRows: 2, maxRows: 3)))['screens'];

        $this->assertSame(['datos', 'mascotas', 'mascotas-2', 'mascotas-3', 'fin'], array_column($screens, 'id'));
        $this->assertSame(['Mascota 1', 'Mascota 2', 'Mascota 3'], array_column(array_slice($screens, 1, 3), 'title'));

        // Fila 1 (obligatoria): sin pregunta, sigue a la fila 2.
        $this->assertSame(['mascotas_1_1', 'mascotas_1_2'], array_column($screens[1]['blocks'], 'key'));
        $this->assertSame('screen:mascotas-2', $screens[1]['next']);

        // Fila 2: "¿Agregar otra?" elige entre la fila 3 y lo que sigue al grupo.
        $more = $screens[2]['blocks'][2];
        $this->assertSame('mascotas_2_more', $more['key']);
        $this->assertSame(['si' => 'screen:mascotas-3', 'no' => 'screen:fin'], $more['branches']);

        // La última fila no pregunta: termina el grupo.
        $this->assertCount(2, $screens[3]['blocks']);
        $this->assertSame('screen:fin', $screens[3]['next']);

        // Cada subcampo con su bloque: texto y opción única con las opciones del CRM.
        $this->assertSame(['type' => 'short_text', 'input_type' => 'text'], array_intersect_key($screens[1]['blocks'][0], ['type' => 0, 'input_type' => 0]));
        $this->assertSame([['id' => 'Gato', 'title' => 'Gato'], ['id' => 'Perro', 'title' => 'Perro']], $screens[1]['blocks'][1]['options']);
        $this->assertSame('Especie · Mascota 1', $screens[1]['blocks'][1]['display_label']);
    }

    public function test_a_group_without_minimum_can_ask_first_and_keeps_its_id_for_incoming_routes(): void
    {
        $screens = FlowDefinitionExpander::expand($this->definition($this->group(minRows: 0, maxRows: 2, askFirst: true)))['screens'];

        $this->assertSame(['datos', 'mascotas', 'mascotas-1', 'mascotas-2', 'fin'], array_column($screens, 'id'));
        $this->assertSame(
            ['si' => 'screen:mascotas-1', 'no' => 'screen:fin'],
            $screens[1]['blocks'][0]['branches'],
        );
        $this->assertSame('mascotas_has', $screens[1]['blocks'][0]['key']);
        // Sin mínimo, la primera fila ya pregunta si agrega otra.
        $this->assertSame('mascotas_1_more', $screens[2]['blocks'][2]['key']);
    }

    public function test_many_choices_become_a_dropdown_and_currency_a_number_with_its_currency(): void
    {
        $group = $this->group(minRows: 1, maxRows: 1);
        $group['fields'] = [
            ['key' => 'raza', 'label' => 'Raza', 'type' => 'select', 'required' => false, 'choices' => array_map(fn ($i) => "Raza {$i}", range(1, 21))],
            ['key' => 'costo', 'label' => 'Costo', 'type' => 'currency', 'required' => false, 'currency' => 'ARS'],
        ];

        $blocks = FlowDefinitionExpander::expand($this->definition($group))['screens'][1]['blocks'];

        $this->assertSame('dropdown', $blocks[0]['type']);
        $this->assertSame(['short_text', 'number', 'En ARS'], [$blocks[1]['type'], $blocks[1]['input_type'], $blocks[1]['helper_text']]);
    }

    public function test_the_compiled_group_routes_through_the_endpoint_and_maps_to_the_repeater_field(): void
    {
        $compiled = (new FlowDefinitionCompiler)->compile($this->definition($this->group(minRows: 1, maxRows: 2)));
        $json = $compiled->flowJson;

        $this->assertSame(
            ['PANTALLA_A' => ['PANTALLA_B'], 'PANTALLA_B' => ['PANTALLA_C', 'PANTALLA_D'], 'PANTALLA_C' => ['PANTALLA_D'], 'PANTALLA_D' => []],
            $json['routing_model'],
        );
        // La última fila no tiene a dónde elegir, pero igual pasa por el CRM para validarse.
        $lastRowFooter = end($json['screens'][2]['layout']['children']);
        $this->assertSame('data_exchange', $lastRowFooter['on-click-action']['name']);
        $this->assertSame(
            [['response_key' => 'nombre', 'target' => 'contact.name'], [
                'kind' => 'repeater',
                'response_key' => 'mascotas',
                'target' => 'custom_data.mascotas',
                'fields' => [1 => 'nombre', 2 => 'especie'],
                'max_rows' => 2,
            ]],
            $compiled->fieldMappings,
        );
    }

    private function definition(array $group): array
    {
        return ['schema' => 1, 'appointment' => null, 'screens' => [
            ['id' => 'datos', 'title' => 'Tus datos', 'footer_label' => 'Seguir', 'blocks' => [
                ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Tu nombre', 'input_type' => 'text', 'required' => true, 'maps_to' => 'contact.name'],
            ]],
            $group,
            ['id' => 'fin', 'title' => 'Gracias', 'footer_label' => 'Enviar', 'next' => 'end', 'blocks' => [
                ['type' => 'long_text', 'key' => 'nota', 'label' => 'Nota', 'required' => false],
            ]],
        ]];
    }

    private function group(int $minRows, int $maxRows, bool $askFirst = false): array
    {
        return [
            'type' => 'repeater', 'id' => 'mascotas', 'key' => 'mascotas', 'field_key' => 'mascotas',
            'title' => 'Tus mascotas', 'row_title' => 'Mascota {n}', 'footer_label' => 'Seguir',
            'more_label' => '¿Agregar otra mascota?', 'ask_first' => $askFirst, 'ask_first_label' => '¿Tenés mascotas?',
            'max_rows' => $maxRows, 'min_rows' => $minRows,
            'fields' => [
                ['key' => 'nombre', 'label' => 'Nombre', 'type' => 'text', 'required' => true],
                ['key' => 'especie', 'label' => 'Especie', 'type' => 'select', 'required' => false, 'choices' => ['Gato', 'Perro']],
            ],
            'next' => 'following',
        ];
    }
}
