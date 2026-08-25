<?php

namespace App\Support;

use App\Enums\ContactFieldType;

/**
 * Plantillas de campos para arrancar una extracción sin configurar todo a mano.
 *
 * Un preset crea ContactField normales: editables, reordenables y borrables
 * como cualquier otro. No son campos de sistema ni quedan atados a la feature,
 * así que el CRM sigue siendo transversal — quien no alquila propiedades no ve
 * nada de esto salvo que lo aplique.
 */
class ExtractionPresetRegistry
{
    public const RENTAL_CONTRACT = 'rental_contract';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [self::RENTAL_CONTRACT];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $preset): ?array
    {
        return self::all()[$preset] ?? null;
    }

    /**
     * @return array<string, array{label: string, fields: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        return [
            self::RENTAL_CONTRACT => [
                'label' => 'Contrato de alquiler',
                'fields' => [
                    [
                        'key' => 'direccion_inmueble',
                        'label' => 'Dirección del inmueble',
                        'type' => ContactFieldType::Text,
                    ],
                    [
                        'key' => 'monto_alquiler',
                        'label' => 'Monto del alquiler',
                        'type' => ContactFieldType::Number,
                    ],
                    [
                        'key' => 'moneda',
                        'label' => 'Moneda',
                        'type' => ContactFieldType::Select,
                        'options' => ['choices' => ['ARS', 'USD']],
                    ],
                    [
                        'key' => 'fecha_inicio',
                        'label' => 'Fecha de inicio',
                        'type' => ContactFieldType::Date,
                    ],
                    [
                        'key' => 'fecha_fin',
                        'label' => 'Fecha de fin',
                        'type' => ContactFieldType::Date,
                    ],
                    [
                        'key' => 'indice_ajuste',
                        'label' => 'Índice de ajuste',
                        'type' => ContactFieldType::Select,
                        'options' => ['choices' => ['IPC', 'ICL', 'Casa Propia', 'Otro']],
                    ],
                    [
                        'key' => 'periodicidad_ajuste',
                        'label' => 'Periodicidad del ajuste',
                        'type' => ContactFieldType::Select,
                        'options' => ['choices' => ['Trimestral', 'Cuatrimestral', 'Semestral', 'Anual']],
                    ],
                    [
                        'key' => 'deposito_garantia',
                        'label' => 'Depósito en garantía',
                        'type' => ContactFieldType::Number,
                    ],
                    [
                        'key' => 'garante',
                        'label' => 'Garante',
                        'type' => ContactFieldType::Text,
                    ],
                    [
                        'key' => 'contrato_pdf',
                        'label' => 'Contrato (PDF)',
                        'type' => ContactFieldType::File,
                    ],
                ],
            ],
        ];
    }
}
