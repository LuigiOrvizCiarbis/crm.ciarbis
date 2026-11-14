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
    public function test_single_screen_without_mappings_closes_without_the_endpoint(): void
    {
        $json = (new FlowDefinitionCompiler)->compile($this->definition([
            $this->screen('a', [
                ['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true, 'prefill' => 'contact_name'],
                ['type' => 'dropdown', 'key' => 'plan', 'label' => 'Plan', 'required' => false, 'options' => [['id' => 'Pro', 'title' => 'Pro']]],
            ]),
        ]))->flowJson;

        $screen = $json['screens'][0];
        $children = $screen['layout']['children'];

        $this->assertSame('7.3', $json['version']);
        $this->assertArrayNotHasKey('data_api_version', $json);
        $this->assertArrayNotHasKey('routing_model', $json);
        $this->assertTrue($screen['terminal']);
        // Sólo los textos son datos de pantalla: precarga y respuestas guardadas.
        $this->assertSame(['nombre'], array_keys($screen['data']));
        $this->assertSame('${data.nombre}', $children[0]['init-value']);
        $this->assertArrayNotHasKey('init-value', $children[1]);
        $this->assertSame(
            ['name' => 'complete', 'payload' => ['nombre' => '${form.nombre}', 'plan' => '${form.plan}']],
            $children[2]['on-click-action'],
        );
    }

    public function test_mapped_questions_are_validated_by_the_endpoint(): void
    {
        $compiled = (new FlowDefinitionCompiler)->compile($this->definition([
            $this->screen('a', [
                ['type' => 'short_text', 'key' => 'email', 'label' => 'Email', 'input_type' => 'email', 'required' => true, 'maps_to' => 'contact.email'],
            ]),
        ]));
        $json = $compiled->flowJson;

        $this->assertSame('4.0', $json['data_api_version']);
        $this->assertSame(['PANTALLA_A' => []], $json['routing_model']);
        $this->assertSame(
            ['name' => 'data_exchange', 'payload' => ['crm_action' => 'submit_screen', 'email' => '${form.email}']],
            $json['screens'][0]['layout']['children'][1]['on-click-action'],
        );
        $this->assertSame([['response_key' => 'email', 'target' => 'contact.email']], $compiled->fieldMappings);
    }

    public function test_intermediate_screens_use_the_endpoint_and_the_last_one_closes_on_its_own(): void
    {
        $json = (new FlowDefinitionCompiler)->compile($this->definition([
            $this->screen('a', [['type' => 'short_text', 'key' => 'nombre', 'label' => 'Nombre', 'input_type' => 'text', 'required' => true]]),
            $this->screen('b', [['type' => 'long_text', 'key' => 'nota', 'label' => 'Nota', 'required' => false]]),
        ]))->flowJson;

        $first = $json['screens'][0];
        $second = $json['screens'][1];

        $this->assertSame(['PANTALLA_A' => ['PANTALLA_B'], 'PANTALLA_B' => []], $json['routing_model']);
        $this->assertSame(
            ['crm_action' => 'submit_screen', 'nombre' => '${form.nombre}'],
            $first['layout']['children'][1]['on-click-action']['payload'],
        );
        $this->assertArrayNotHasKey('terminal', $first);
        $this->assertTrue($second['terminal']);
        $this->assertSame(['name' => 'complete', 'payload' => ['nota' => '${form.nota}']], $second['layout']['children'][1]['on-click-action']);
    }

    public function test_branches_become_routes_and_a_screen_that_can_end_is_terminal(): void
    {
        $choice = [
            'type' => 'single_choice', 'key' => 'cliente', 'label' => '¿Sos cliente?', 'required' => true,
            'options' => [['id' => 'si', 'title' => 'Sí'], ['id' => 'no', 'title' => 'No'], ['id' => 'luego', 'title' => 'Más tarde']],
            'branches' => ['si' => 'screen:numero', 'no' => 'screen:alta', 'luego' => 'end'],
        ];
        $json = (new FlowDefinitionCompiler)->compile($this->definition([
            $this->screen('inicio', [$choice]),
            $this->screen('numero', [['type' => 'short_text', 'key' => 'numero', 'label' => 'Número', 'input_type' => 'text', 'required' => true]]) + ['next' => 'end'],
            $this->screen('alta', [['type' => 'short_text', 'key' => 'empresa', 'label' => 'Empresa', 'input_type' => 'text', 'required' => true]]),
        ]))->flowJson;

        $this->assertSame([
            'PANTALLA_A' => ['PANTALLA_B', 'PANTALLA_C'],
            'PANTALLA_B' => [],
            'PANTALLA_C' => [],
        ], $json['routing_model']);
        $this->assertTrue($json['screens'][0]['terminal']);
        $this->assertSame('data_exchange', $json['screens'][0]['layout']['children'][1]['on-click-action']['name']);
        $this->assertSame('complete', $json['screens'][1]['layout']['children'][1]['on-click-action']['name']);
    }

    public function test_appointment_follows_every_ending_and_books_from_saved_answers(): void
    {
        $definition = $this->definition([
            $this->screen('a', [
                ['type' => 'short_text', 'key' => 'email', 'label' => 'Email', 'input_type' => 'email', 'required' => false, 'maps_to' => 'contact.email'],
                ['type' => 'long_text', 'key' => 'motivo', 'label' => 'Motivo', 'required' => false],
            ]),
        ]);
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
        $this->assertSame(['crm_action' => 'submit_screen', 'email' => '${form.email}', 'motivo' => '${form.motivo}'], $footer['payload']);
        $this->assertSame(['TURNO_FECHA', 'TURNO_HORARIO'], array_column(array_slice($json['screens'], 1), 'id'));
        $this->assertSame(['crm_action' => 'book_appointment', 'slot' => '${form.slot}'], $slotFooter['payload']);
    }

    public function test_images_compile_to_meta_components_with_their_real_proportions(): void
    {
        $wide = $this->png(40, 20);
        $json = (new FlowDefinitionCompiler)->compile($this->definition([
            $this->screen('a', [
                ['type' => 'image', 'src' => $wide, 'alt_text' => 'Logo', 'size' => 'medium', 'scale_type' => 'contain'],
                ['type' => 'image_carousel', 'aspect_ratio' => '16:9', 'scale_type' => 'cover', 'images' => [['src' => $wide, 'alt_text' => 'Local']]],
                [
                    'type' => 'single_choice', 'key' => 'equipo', 'label' => 'Equipo', 'required' => true, 'media_size' => 'large',
                    'options' => [['id' => 'Boca', 'title' => 'Boca', 'image' => $wide, 'alt_text' => 'Escudo'], ['id' => 'River', 'title' => 'River']],
                ],
            ]),
        ]))->flowJson;

        [$image, $carousel, $choice] = $json['screens'][0]['layout']['children'];

        $this->assertSame(
            ['type' => 'Image', 'src' => $wide, 'height' => 200, 'scale-type' => 'contain', 'aspect-ratio' => 2.0, 'alt-text' => 'Logo'],
            $image,
        );
        $this->assertSame(
            ['type' => 'ImageCarousel', 'aspect-ratio' => '16:9', 'scale-type' => 'cover', 'images' => [['src' => $wide, 'alt-text' => 'Local']]],
            $carousel,
        );
        $this->assertSame('large', $choice['media-size']);
        $this->assertSame(
            [['id' => 'Boca', 'title' => 'Boca', 'image' => $wide, 'alt-text' => 'Escudo'], ['id' => 'River', 'title' => 'River']],
            $choice['data-source'],
        );
        // Las imágenes no son preguntas: no entran en la respuesta.
        $this->assertSame(['equipo' => '${form.equipo}'], $json['screens'][0]['layout']['children'][3]['on-click-action']['payload']);
    }

    private function definition(array $screens): array
    {
        return ['schema' => 1, 'screens' => $screens, 'appointment' => null];
    }

    private function screen(string $id, array $blocks): array
    {
        return ['id' => $id, 'title' => ucfirst($id), 'footer_label' => 'Seguir', 'blocks' => $blocks];
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return base64_encode((string) ob_get_clean());
    }
}
