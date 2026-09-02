<?php

namespace App\Support;

/**
 * `timezone:all` valida contra DateTimeZone::ALL, que excluye los alias
 * legacy de la IANA. Los navegadores todavía reportan algunos vía
 * Intl.supportedValuesOf(), así que se canonizan antes de validar.
 *
 * Tercer consumidor de este mapa (antes duplicado en StoreAutomationRequest y
 * TaskController): BillingConfig también valida timezone con `timezone:all`.
 */
final class TimezoneAliases
{
    private const MAP = [
        'America/Buenos_Aires' => 'America/Argentina/Buenos_Aires',
    ];

    public static function canonicalize(string $timezone): string
    {
        return self::MAP[$timezone] ?? $timezone;
    }
}
