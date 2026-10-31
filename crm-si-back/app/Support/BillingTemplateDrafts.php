<?php

namespace App\Support;

use App\Enums\TemplateCategory;
use App\Models\Tenant;

/**
 * Borradores de las plantillas de WhatsApp que necesita el módulo de cobranzas.
 *
 * No crea nada en Meta: sólo arma el payload que después se manda por el
 * endpoint que ya existe (POST /channels/{channel}/templates). El usuario
 * puede editar el texto antes de enviarlo — el contenido es el mensaje que su
 * negocio le manda a sus clientes, así que un texto fijo no alcanza, y Meta no
 * permite editar una plantilla ya aprobada.
 *
 * Todas son UTILITY, no MARKETING: un recordatorio de pago es transaccional.
 * Mandarlas como MARKETING consumiría el cap por usuario y exigiría
 * consentimiento previo (ver BroadcastAudienceService), además de arriesgar un
 * rechazo de Meta por INCORRECT_CATEGORY.
 */
final class BillingTemplateDrafts
{
    /**
     * Claves estables de cada borrador: identifican para qué regla es cada
     * plantilla cuando el front las manda de a una y cuando el provisioning
     * las busca después.
     */
    public const REMINDER = 'reminder';

    public const OVERDUE = 'overdue';

    public const TRIAL = 'trial';

    /**
     * @return list<array{key: string, label: string, description: string, name: string, language: string, category: string, parameter_format: string, components: list<array<string, mixed>>}>
     */
    public static function all(Tenant $tenant, string $language = 'es_AR'): array
    {
        return [
            self::draft(
                $tenant,
                $language,
                key: self::REMINDER,
                label: 'Aviso previo al vencimiento',
                description: 'Se envía unos días antes de que venza el pago, sólo a quien todavía no pagó.',
                body: 'Hola {{nombre}}, te recordamos que tu pago vence el {{fecha}}. Cualquier duda, respondé este mensaje.',
                examples: ['nombre' => 'María', 'fecha' => '10/09'],
            ),
            self::draft(
                $tenant,
                $language,
                key: self::OVERDUE,
                label: 'Reclamo posterior al vencimiento',
                description: 'Se envía unos días después del vencimiento, sólo a quien figura impago.',
                body: 'Hola {{nombre}}, tu pago del {{fecha}} figura pendiente. Si ya lo hiciste, avisanos respondiendo este mensaje.',
                examples: ['nombre' => 'María', 'fecha' => '10/09'],
            ),
            self::draft(
                $tenant,
                $language,
                key: self::TRIAL,
                label: 'Aviso de fin de prueba',
                description: 'Opcional. Se envía antes de que termine el período de prueba.',
                body: 'Hola {{nombre}}, tu período de prueba termina el {{fecha}}. Escribinos para continuar.',
                examples: ['nombre' => 'María', 'fecha' => '10/09'],
            ),
        ];
    }

    /**
     * @param  array<string, string>  $examples
     * @return array{key: string, label: string, description: string, name: string, language: string, category: string, parameter_format: string, components: list<array<string, mixed>>}
     */
    private static function draft(
        Tenant $tenant,
        string $language,
        string $key,
        string $label,
        string $description,
        string $body,
        array $examples,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'name' => self::templateName($tenant, $key),
            'language' => $language,
            'category' => TemplateCategory::Utility->value,
            'parameter_format' => 'named',
            'components' => [
                [
                    'type' => 'BODY',
                    'text' => $body,
                    'example' => [
                        'body_text_named_params' => array_map(
                            fn (string $name, string $example) => ['param_name' => $name, 'example' => $example],
                            array_keys($examples),
                            array_values($examples),
                        ),
                    ],
                ],
                [
                    'type' => 'FOOTER',
                    'text' => self::footer($tenant),
                ],
            ],
        ];
    }

    /**
     * El nombre lleva el id del tenant como sufijo: Meta rechaza crear dos
     * plantillas con el mismo nombre en una WABA, y una WABA puede estar
     * compartida. Sin el sufijo, el segundo tenant que provisiona chocaría con
     * el primero.
     */
    private static function templateName(Tenant $tenant, string $key): string
    {
        return "cobranza_{$key}_t{$tenant->id}";
    }

    /**
     * El footer identifica al negocio: sin él el mensaje llega sin contexto y
     * Meta suele marcarlo como poco claro. Se recorta a 60 caracteres, el
     * límite de Meta para FOOTER.
     */
    private static function footer(Tenant $tenant): string
    {
        $name = trim((string) $tenant->name);

        return $name === '' ? 'Equipo de cobranzas' : mb_substr($name, 0, 60);
    }
}
