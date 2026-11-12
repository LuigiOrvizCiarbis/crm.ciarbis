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
    public function test_single_screen_submits_its_answers_to_the_endpoint_and_closes(): void
    {
        $compiled = (new FlowDefinitionCompiler)->compile($this->definition([[
            'title' => 'Tus datos',
            'footer_label' => 'Enviar',
            'blocks' => [
                ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true, 'prefill' => 'contact_name', 'maps_to' => 'contact.name'],
                ['type' => 'short_text', 'key' => 'email', 'label' => 'Email', 'input_type' => 'email', 'required' => true, 'maps_to' => 'contact.email'],
                ['type' => 'dropdown', 'key' => 'plan', 'label' => 'Plan', 'required' => false, 'options' => [['id' => 'Pro', 'title' => 'Pro']]],
            ],
        ]]));

        $json = $compiled->flowJson;
        $screen = $json['screens'][0];
        $children = $screen['layout']['children'];

        $this->assertSame('7.3', $json['version']);
        $this->assertSame('4.0', $json['data_api_version']);
        $this->assertSame(['PANTALLA_A' => []], $json['routing_model']);
        $this->assertSame('PANTALLA_A', $screen['id']);
        $this->assertTrue($screen['terminal']);
        // Sólo los textos se restauran: sus respuestas son datos de la pantalla.
        $this->assertSame(['nombre', 'email'], array_keys($screen['data']));
        $this->assertSame('${data.nombre}', $children[0]['init-value']);
        $this->assertArrayNotHasKey('init-value', $children[2]);
        $this->assertSame([
            'type' => 'Footer',
            'label' => 'Enviar',
            'on-click-action' => [
                'name' => 'data_exchange',
                'payload' => ['crm_action' => 'submit_screen', 'nombre' => '${form.nombre}', 'email' => '${form.email}', 'plan' => '${form.plan}'],
            ],
        ], $children[3]);
        $this->assertSame([
            ['response_key' => 'nombre', 'target' => 'contact.name'],
            ['response_key' => 'email', 'target' => 'contact.email'],
        ], $compiled->fieldMappings);
    }

    public function test_each_screen_submits_only_its_own_answers_and_names_the_next_one(): void
    {
        $json = (new FlowDefinitionCompiler)->compile($this->definition([
            ['title' => 'Uno', 'footer_label' => 'Seguir', 'blocks' => [
                ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true],
            ]],
            ['title' => 'Dos', 'footer_label' => 'Enviar', 'blocks' => [
                ['type' => 'long_text', 'key' => 'nota', 'label' => 'Nota', 'required' => false],
            ]],
        ]))->flowJson;

        $first = $json['screens'][0]['layout']['children'];
        $second = $json['screens'][1]['layout']['children'];

        $this->assertSame(['PANTALLA_A' => ['PANTALLA_B'], 'PANTALLA_B' => []], $json['routing_model']);
        $this->assertSame(
            ['crm_action' => 'submit_screen', 'next_screen' => 'PANTALLA_B', 'nombre' => '${form.nombre}'],
            $first[1]['on-click-action']['payload'],
        );
        $this->assertSame(['crm_action' => 'submit_screen', 'nota' => '${form.nota}'], $second[1]['on-click-action']['payload']);
        $this->assertSame('${data.nota}', $second[0]['init-value']);
        $this->assertArrayNotHasKey('terminal', $json['screens'][0]);
        $this->assertTrue($json['screens'][1]['terminal']);
    }

    public function test_appointment_follows_the_last_screen_and_books_from_saved_answers(): void
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

        $this->assertSame([
            'PANTALLA_A' => ['TURNO_FECHA'],
            'TURNO_FECHA' => ['TURNO_HORARIO'],
            'TURNO_HORARIO' => [],
        ], $json['routing_model']);
        $this->assertArrayNotHasKey('terminal', $json['screens'][0]);
        $this->assertSame('submit_screen', $footer['payload']['crm_action']);
        $this->assertSame('TURNO_FECHA', $footer['payload']['next_screen']);
        $this->assertSame(['TURNO_FECHA', 'TURNO_HORARIO'], array_column(array_slice($json['screens'], 1), 'id'));
        $this->assertSame(['crm_action' => 'book_appointment', 'slot' => '${form.slot}'], $slotFooter['payload']);
    }

    private function definition(array $screens): array
    {
        return ['schema' => 1, 'screens' => $screens, 'appointment' => null];
    }
}
