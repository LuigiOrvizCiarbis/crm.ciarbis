<?php

namespace App\Support;

class PhoneNumberNormalizer
{
    /**
     * Clave de deduplicación de audiencia: solo dígitos, colapsando el 9 del
     * móvil argentino (5492235550101 -> 542235550101).
     *
     * NO usar para el envío a Meta: WhatsAppTemplateService::sendTemplateMessage
     * normaliza el string crudo con strpos($to,'549')===0, y hay contactos
     * guardados con "+" adelante para los que ese strpos da false. Aplicar
     * preg_replace primero cambiaría el número al que se les envía en
     * producción. Unificar ambos call sites es una limpieza aparte.
     */
    public static function dedupeKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return str_starts_with($digits, '549') ? '54'.substr($digits, 3) : $digits;
    }
}
