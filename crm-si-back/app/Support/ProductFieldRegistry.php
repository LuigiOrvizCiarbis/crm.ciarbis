<?php

namespace App\Support;

use App\Enums\ContactFieldType;

class ProductFieldRegistry
{
    /**
     * Slugs that cannot be used as custom field keys (reserved for system columns
     * and well-known fields on the products table).
     *
     * @return list<string>
     */
    public static function reservedKeys(): array
    {
        return [
            'id',
            'tenant_id',
            'created_by',
            'name',
            'price',
            'description',
            'is_active',
            'source',
            'external_id',
            'external_type',
            'requires_shipping',
            'external_sync_run_id',
            'custom_data',
            'created_at',
            'updated_at',
            'deleted_at',
        ];
    }

    /**
     * System-managed standard fields for products. Only `name` is fixed and always
     * present; price/description/is_active are seeded as editable ProductField
     * defaults so the catalogue stays transversal to any industry.
     *
     * @return list<array<string, mixed>>
     */
    public static function standard(): array
    {
        return [
            [
                'key' => 'name',
                'label' => 'Nombre',
                'type' => ContactFieldType::Text->value,
                'options' => null,
                'is_required' => true,
                'is_unique' => false,
                'is_system' => true,
                'display_order' => 0,
            ],
        ];
    }
}
