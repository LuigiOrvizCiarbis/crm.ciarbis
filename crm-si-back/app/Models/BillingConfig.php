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

    /**
     * Estados que el motor de cobranzas entiende de forma literal:
     * billing:roll-cycle los compara para decidir el siguiente ciclo, las
     * reglas de billing:provision filtran por ellos, y tener el campo de
     * estado cargado con uno de estos es lo que hace que un contacto cuente
     * como cliente con ciclo activo (ContactController::index, billing=clients).
     *
     * No son vocabulario libre del tenant como el nombre del campo: el campo
     * Select puede tener choices adicionales, pero estos tres tienen que estar.
     *
     * @var list<string>
     */
    public const STATUSES = ['al_dia', 'impago', 'en_prueba'];

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
