<?php

namespace Tests\Feature;

use App\Services\UnitedStatesPhoneDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UnitedStatesPhoneDetectorTest extends TestCase
{
    private UnitedStatesPhoneDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new UnitedStatesPhoneDetector;
    }

    #[DataProvider('unitedStatesNumbers')]
    public function test_detects_united_states_numbers(string $phone): void
    {
        $this->assertTrue($this->detector->isUnitedStates($phone), $phone.' debería detectarse como EE.UU.');
    }

    /** @return array<string, array{string}> */
    public static function unitedStatesNumbers(): array
    {
        return [
            'Miami 786 (existe en producción)' => ['17866085755'],
            'Miami 305' => ['13057812143'],
            'Seattle 206' => ['12066409886'],
            'Nueva York 347' => ['13478451506'],
            // Territorios de EE.UU.: Meta los trata como Estados Unidos.
            'Puerto Rico 787' => ['17871234567'],
            'Puerto Rico 939' => ['19391234567'],
            'Islas Vírgenes de EE.UU. 340' => ['13401234567'],
            'Guam 671' => ['16711234567'],
            'con prefijo +' => ['+17866085755'],
            'con separadores' => ['+1 (786) 608-5755'],
            'con guiones' => ['1-786-608-5755'],
        ];
    }

    #[DataProvider('nonUnitedStatesNumbers')]
    public function test_does_not_flag_numbers_outside_the_united_states(string $phone): void
    {
        $this->assertFalse($this->detector->isUnitedStates($phone), $phone.' no debería detectarse como EE.UU.');
    }

    /** @return array<string, array{string}> */
    public static function nonUnitedStatesNumbers(): array
    {
        return [
            // Canadá comparte el +1 y Meta sí entrega marketing ahí. Filtrar
            // por prefijo dejaría a estos contactos sin recibir la difusión.
            'Ontario 289 (existe en producción)' => ['12895567358'],
            'Toronto 416' => ['14161234567'],
            'Vancouver 604' => ['16041234567'],
            'Montreal 514' => ['15141234567'],
            // Overlays canadienses recientes: son los que más fácil se quedan
            // afuera de la lista y excluyen contactos válidos en silencio.
            'Ontario 942 (en servicio 2025)' => ['19421234567'],
            'Columbia Británica 257 (en servicio 2025)' => ['12571234567'],
            'Newfoundland 879 (en servicio 2024)' => ['18791234567'],
            'Ontario 683 (en servicio 2022)' => ['16831234567'],
            'Alberta 368' => ['13681234567'],
            'Ontario 382' => ['13821234567'],
            'Nuevo Brunswick 428' => ['14281234567'],
            // Caribe: también +1, tampoco es EE.UU.
            'República Dominicana 809' => ['18091234567'],
            'República Dominicana 849' => ['18491234567'],
            'Jamaica 876' => ['18761234567'],
            'Jamaica 658' => ['16581234567'],
            'Bahamas 242' => ['12421234567'],
            'Trinidad y Tobago 868' => ['18681234567'],
            // Otros países
            'Argentina' => ['5492235550101'],
            'Argentina con +' => ['+5492235550101'],
            'Brasil' => ['5521987654321'],
            'España' => ['34612345678'],
        ];
    }

    #[DataProvider('malformedNumbers')]
    public function test_malformed_numbers_are_not_flagged(?string $phone): void
    {
        $this->assertFalse($this->detector->isUnitedStates($phone));
    }

    /** @return array<string, array{string|null}> */
    public static function malformedNumbers(): array
    {
        return [
            'null' => [null],
            'vacío' => [''],
            'solo texto' => ['sin número'],
            'demasiado corto' => ['1786608'],
            'demasiado largo' => ['178660857551234'],
            'nacional sin código de país' => ['7866085755'],
            // El primer dígito del código de área nunca es 0 ni 1 en el NANP.
            'código de área empieza con 0' => ['10866085755'],
            'código de área empieza con 1' => ['11866085755'],
        ];
    }

    /**
     * Ante un número ambiguo se prefiere intentar el envío: excluir de más deja
     * al usuario sin alcanzar a alguien que sí podía recibir el mensaje, y eso
     * no queda visible en ningún lado.
     */
    public function test_unknown_shapes_default_to_sending(): void
    {
        $this->assertFalse($this->detector->isUnitedStates('001786608575'));
    }

    /**
     * Recordatorio activo de mantenimiento.
     *
     * El CNAC ya aprobó estos códigos para Canadá pero todavía no están en
     * servicio, así que hoy se clasifican como EE.UU. sin causar daño: nadie
     * tiene un número con ellos. Cuando se activen hay que sumarlos a
     * CANADA_AREA_CODES, y este test falla para avisarlo en vez de depender de
     * que alguien recuerde revisar el PDF.
     *
     * @see https://www.cnac.ca/npa_codes/NPA_History.pdf
     */
    public function test_pending_canadian_area_codes_are_still_documented_as_pending(): void
    {
        $pending = [
            '273' => 'Québec, previsto 2027',
            '851' => 'Nueva Escocia y PEI, previsto 2028',
            '387' => 'Ontario, reservado sin fecha',
            '568' => 'Alberta, reservado sin fecha',
        ];

        foreach ($pending as $areaCode => $description) {
            $this->assertTrue(
                $this->detector->isUnitedStates('1'.$areaCode.'1234567'),
                "El código {$areaCode} ({$description}) dejó de tratarse como EE.UU. Si entró en servicio en Canadá, "
                .'sumalo a CANADA_AREA_CODES y sacalo de esta lista de pendientes.'
            );
        }
    }
}
