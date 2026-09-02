<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property bool $enabled
 * @property string $due_date_field_key
 * @property string $status_field_key
 * @property string $overdue_cycles_field_key
 * @property string|null $externally_managed_field_key
 * @property string $cycle_unit
 * @property int $cycle_length
 * @property string $timezone
 * @property int $grace_days
 * @property Carbon|null $last_rolled_at
 */
class BillingConfig extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'enabled',
        'due_date_field_key',
        'status_field_key',
        'overdue_cycles_field_key',
        'externally_managed_field_key',
        'cycle_unit',
        'cycle_length',
        'timezone',
        'grace_days',
        'last_rolled_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'cycle_length' => 'integer',
            'grace_days' => 'integer',
            'last_rolled_at' => 'datetime',
        ];
    }
}
