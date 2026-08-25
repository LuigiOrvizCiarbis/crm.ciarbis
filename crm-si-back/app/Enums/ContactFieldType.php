<?php

namespace App\Enums;

use Illuminate\Validation\Rule;

enum ContactFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Currency = 'currency';
    case Date = 'date';
    case Boolean = 'boolean';
    case Select = 'select';
    case MultiSelect = 'multi_select';
    case Email = 'email';
    case Url = 'url';
    case Phone = 'phone';
    case File = 'file';
    case Repeater = 'repeater';

    public const DEFAULT_CURRENCY = 'ARS';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function requiresOptions(): bool
    {
        return in_array($this, [self::Select, self::MultiSelect, self::Repeater, self::Currency], true);
    }

    /**
     * Divisas admitidas por un campo Currency.
     *
     * Lista corta y cerrada a propósito: el valor guardado sigue siendo un
     * número plano, la divisa vive en la definición del campo. No hay
     * conversión entre divisas — un importe en USD y otro en ARS no son
     * comparables y el CRM no intenta fingir que lo son.
     *
     * @return list<string>
     */
    public static function currencies(): array
    {
        return ['ARS', 'USD'];
    }

    /**
     * Validation rules for a single value of this type.
     *
     * @param  array<string, mixed>|null  $options
     * @return array<int, mixed>
     */
    public function valueRules(?array $options = null): array
    {
        $choices = is_array($options['choices'] ?? null) ? $options['choices'] : [];

        return match ($this) {
            self::Text => ['nullable', 'string', 'max:1000'],
            self::Number => ['nullable', 'numeric'],
            // El valor de un Currency es el importe a secas; la divisa la define
            // el campo (options.currency), no cada valor.
            self::Currency => ['nullable', 'numeric'],
            self::Date => ['nullable', 'date'],
            self::Boolean => ['nullable', 'boolean'],
            self::Select => ['nullable', 'string', Rule::in($choices)],
            self::MultiSelect => ['nullable', 'array'],
            self::Email => ['nullable', 'email', 'max:255'],
            self::Url => ['nullable', 'url', 'max:2048'],
            self::Phone => ['nullable', 'string', 'max:50'],
            // El valor de un campo File es el id de un MediaAsset: el archivo lo
            // posee la app, así que su URL pública está garantizada. La
            // pertenencia al tenant la refuerza ValidContactCustomData, que sí
            // conoce el tenant en contexto.
            self::File => ['nullable', 'integer'],
            // Repeater values are validated against their nested schema by
            // ValidContactCustomData / ValidProductCustomData.
            self::Repeater => ['nullable', 'array'],
        };
    }

    /**
     * Rules applied to each item of an array-typed value (multi-select).
     *
     * @param  array<string, mixed>|null  $options
     * @return array<int, mixed>|null
     */
    public function itemRules(?array $options = null): ?array
    {
        if ($this !== self::MultiSelect) {
            return null;
        }

        $choices = is_array($options['choices'] ?? null) ? $options['choices'] : [];

        return ['string', Rule::in($choices)];
    }
}
