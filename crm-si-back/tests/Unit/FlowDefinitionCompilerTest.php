<?php

namespace Tests\Unit;

use App\Services\Flows\Builder\FlowDefinitionCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Fija la forma exacta del Flow JSON que el constructor manda a Meta: una
 * clave o referencia mal armada sólo se vería al publicar contra Meta.
 */
class FlowDefinitionCompilerTest extends TestCase
{
    public function test_lead_capture_compiles_to_a_single_terminal_screen(): void
    {
        $compiled = (new FlowDefinitionCompiler)->compile($this->definition([[
            'title' => 'Tus datos',
            'footer_label' => 'Enviar',
            'blocks' => [
                ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true, 'prefill' => 'contact_name', 'maps_to' => 'contact.name'],
                ['type' => 'short_text', 'key' => 'email', 'label' => 'Email', 'input_type' => 'email', 'required' => true, 'prefill' => 'contact_email', 'maps_to' => 'contact.email'],
                ['type' => 'long_text', 'key' => 'consulta', 'label' => 'Consulta', 'required' => false],
            ],
        ]]));

        $json = $compiled->flowJson;
        $screen = $json['screens'][0];
        $children = $screen['layout']['children'];

        $this->assertSame('7.3', $json['version']);
        $this->assertArrayNotHasKey('data_api_version', $json);
        $this->assertArrayNotHasKey('routing_model', $json);
        $this->assertSame('PANTALLA_1', $screen['id']);
        $this->assertTrue($screen['terminal']);
        $this->assertSame(['contact_name', 'contact_email'], array_keys($screen['data']));
        $this->assertSame('${data.contact_name}', $children[0]['init-value']);
        $this->assertSame('TextInput', $children[0]['type']);
        $this->assertSame('TextArea', $children[2]['type']);
        $this->assertSame([
            'type' => 'Footer',
            'label' => 'Enviar',
            'on-click-action' => [
                'name' => 'complete',
                'payload' => ['nombre' => '${form.nombre}', 'email' => '${form.email}', 'consulta' => '${form.consulta}'],
            ],
        ], $children[3]);
        $this->assertSame([
            ['response_key' => 'nombre', 'target' => 'contact.name'],
            ['response_key' => 'email', 'target' => 'contact.email'],
        ], $compiled->fieldMappings);
    }

    public function test_two_screens_navigate_and_reference_answers_globally(): void
    {
        $json = (new FlowDefinitionCompiler)->compile($this->definition([
            ['title' => 'Uno', 'footer_label' => 'Seguir', 'blocks' => [
                ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true, 'prefill' => 'contact_name'],
            ]],
            ['title' => 'Dos', 'footer_label' => 'Enviar', 'blocks' => [
                ['type' => 'long_text', 'key' => 'nota', 'label' => 'Nota', 'required' => false, 'prefill' => 'contact_email'],
            ]],
        ]))->flowJson;

        $first = $json['screens'][0]['layout']['children'];
        $second = $json['screens'][1]['layout']['children'];

        $this->assertSame('navigate', $first[1]['on-click-action']['name']);
        $this->assertSame(['type' => 'screen', 'name' => 'PANTALLA_2'], $first[1]['on-click-action']['next']);
        $this->assertSame('{}', json_encode($first[1]['on-click-action']['payload']));
        $this->assertSame('${screen.PANTALLA_1.data.contact_email}', $second[0]['init-value']);
        $this->assertSame(
            ['nombre' => '${screen.PANTALLA_1.form.nombre}', 'nota' => '${form.nota}'],
            $second[1]['on-click-action']['payload'],
        );
        $this->assertArrayNotHasKey('terminal', $json['screens'][0]);
        $this->assertTrue($json['screens'][1]['terminal']);
    }

    public function test_appointment_adds_endpoint_screens_and_routing(): void
    {
        $definition = $this->definition([[
            'title' => 'Tus datos',
            'footer_label' => 'Continuar',
            'blocks' => [
                ['type' => 'short_text', 'key' => 'email', 'label' => 'Email', 'input_type' => 'email', 'required' => false, 'maps_to' => 'contact.email'],
                ['type' => 'long_text', 'key' => 'motivo', 'label' => 'Motivo', 'required' => false],
            ],
        ]]);
        $definition['appointment'] = [
            'date_screen_title' => 'Elegí el día',
            'date_label' => 'Fecha',
            'slot_screen_title' => 'Elegí el horario',
            'slot_label' => 'Horario',
            'confirm_label' => 'Reservar turno',
            'reason_key' => 'motivo',
        ];

        $json = (new FlowDefinitionCompiler)->compile($definition)->flowJson;
        $footer = $json['screens'][0]['layout']['children'][2]['on-click-action'];
        $slotFooter = $json['screens'][2]['layout']['children'][1]['on-click-action'];

        $this->assertSame('4.0', $json['data_api_version']);
        $this->assertSame([
            'PANTALLA_1' => ['TURNO_FECHA'],
            'TURNO_FECHA' => ['TURNO_HORARIO'],
            'TURNO_HORARIO' => [],
        ], $json['routing_model']);
        $this->assertSame('data_exchange', $footer['name']);
        $this->assertSame('appointment_dates', $footer['payload']['crm_action']);
        $this->assertSame('TURNO_FECHA', $footer['payload']['next_screen']);
        $this->assertSame(['TURNO_FECHA', 'TURNO_HORARIO'], array_column(array_slice($json['screens'], 1), 'id'));
        $this->assertSame([
            'crm_action' => 'book_appointment',
            'slot' => '${form.slot}',
            'appointment_email' => '${screen.PANTALLA_1.form.email}',
            'appointment_reason' => '${screen.PANTALLA_1.form.motivo}',
        ], $slotFooter['payload']);
    }

    private function definition(array $screens): array
    {
        return ['schema' => 1, 'screens' => $screens, 'appointment' => null];
    }
}
