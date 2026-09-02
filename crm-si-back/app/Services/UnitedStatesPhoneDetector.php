<?php

namespace App\Services;

/**
 * Detecta si un teléfono pertenece a un usuario de Estados Unidos.
 *
 * Meta no entrega plantillas de categoría MARKETING a números de EE.UU. desde
 * el 1 de abril de 2025, sin fecha de fin anunciada. Los mensajes se aceptan
 * pero nunca llegan, así que conviene excluirlos de la audiencia antes de
 * gastar cupo del límite de 24h.
 *
 * El prefijo +1 NO alcanza como criterio: el NANP lo comparten EE.UU., Canadá
 * y buena parte del Caribe, y a esos destinos Meta sí entrega marketing. La
 * discriminación es por código de área.
 *
 * @see https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/marketing-templates/per-user-limits
 */
class UnitedStatesPhoneDetector
{
    /**
     * Códigos de área del NANP asignados a Canadá, incluidos los overlays en
     * servicio.
     *
     * Fuente: "Canadian Geographic Area Code Relief History" del Canadian
     * Numbering Administration Consortium (CNAC), revisión del 2026-05-29.
     *
     * @see https://www.cnac.ca/npa_codes/NPA_History.pdf
     *
     * MANTENIMIENTO: Canadá activa overlays cada uno o dos años y cada código
     * faltante deja a esos contactos fuera de las difusiones de marketing en
     * silencio. Revisar el PDF del CNAC al menos una vez al año. Ya hay tres
     * códigos con relief aprobado que todavía no están en servicio y deben
     * sumarse cuando se activen: 273 (Québec, 2027), 851 (Nueva Escocia y PEI,
     * 2028), y 387 (Ontario) y 568 (Alberta), ambos reservados sin fecha.
     *
     * @var list<string>
     */
    private const CANADA_AREA_CODES = [
        '204', '226', '236', '249', '250', '257', '263', '289',
        '306', '343', '354', '365', '367', '368', '382',
        '403', '416', '418', '428', '431', '437', '438', '450', '468', '474',
        '506', '514', '519', '548', '579', '581', '584', '587',
        '604', '613', '639', '647', '672', '683',
        '705', '709', '742', '753', '778', '780', '782',
        '807', '819', '825', '867', '873', '879',
        '902', '905', '942',
    ];

    /**
     * Códigos de área del NANP asignados a países y territorios del Caribe que
     * comparten el +1 sin ser Estados Unidos.
     *
     * Deliberadamente NO incluye Puerto Rico (787, 939), Islas Vírgenes de
     * EE.UU. (340), Guam (671), Marianas del Norte (670) ni Samoa Americana
     * (684). Meta define el alcance como "a +1 dialing code and a US area
     * code" sin aclarar qué pasa con los territorios; se los cuenta como
     * EE.UU. porque el NANP les asigna códigos de área estadounidenses. Es una
     * interpretación: si se confirma que Meta sí entrega marketing ahí, mover
     * esos códigos a esta lista.
     *
     * @var list<string>
     */
    private const NON_US_NANP_AREA_CODES = [
        '242', // Bahamas
        '246', // Barbados
        '264', // Anguila
        '268', // Antigua y Barbuda
        '284', // Islas Vírgenes Británicas
        '345', // Islas Caimán
        '441', // Bermudas
        '473', // Granada
        '649', // Islas Turcas y Caicos
        '658', '876', // Jamaica
        '664', // Montserrat
        '721', // Sint Maarten
        '758', // Santa Lucía
        '767', // Dominica
        '784', // San Vicente y las Granadinas
        '809', '829', '849', // República Dominicana
        '868', // Trinidad y Tobago
        '869', // San Cristóbal y Nieves
    ];

    /**
     * Un número es de EE.UU. cuando pertenece al NANP (+1, 10 dígitos
     * nacionales) y su código de área no está asignado a otro país.
     *
     * Ante la duda se devuelve false: excluir de más deja al usuario sin
     * enviarle a alguien que sí podía recibir el mensaje, y eso es un daño
     * silencioso. El envío fallido, en cambio, queda visible en la difusión.
     */
    public function isUnitedStates(?string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        // El NANP en formato internacional son 11 dígitos: 1 + área (3) + abonado (7).
        if (strlen($digits) !== 11 || ! str_starts_with($digits, '1')) {
            return false;
        }

        $areaCode = substr($digits, 1, 3);

        // El primer dígito del código de área nunca es 0 ni 1 en el NANP; si lo
        // es, el número está mal formado y no se puede afirmar que sea de EE.UU.
        if (! preg_match('/^[2-9]\d{2}$/', $areaCode)) {
            return false;
        }

        if (in_array($areaCode, self::CANADA_AREA_CODES, true)) {
            return false;
        }

        if (in_array($areaCode, self::NON_US_NANP_AREA_CODES, true)) {
            return false;
        }

        return true;
    }
}
