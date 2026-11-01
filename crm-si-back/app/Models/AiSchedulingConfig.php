<?php

namespace App\Models;

use App\Enums\AiSchedulingMode;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Config de la agenda de turnos que maneja la IA, una por tenant.
 *
 * @property bool $enabled
 * @property AiSchedulingMode $mode
 * @property ?string $criteria
 * @property ?int $default_user_id
 * @property string $timezone
 * @property int $meeting_duration_minutes
 * @property int $slot_interval_minutes
 * @property int $min_notice_hours
 * @property int $max_days_ahead
 * @property array<string, list<array{start: string, end: string}>> $working_hours
 */
class AiSchedulingConfig extends Model
{
    use BelongsToTenant;

    /**
     * Claves de día en `working_hours`, indexadas por Carbon::dayOfWeek
     * (0 = domingo).
     */
    public const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public const DEFAULT_WORKING_HOURS = [
        'mon' => [['start' => '09:00', 'end' => '18:00']],
        'tue' => [['start' => '09:00', 'end' => '18:00']],
        'wed' => [['start' => '09:00', 'end' => '18:00']],
        'thu' => [['start' => '09:00', 'end' => '18:00']],
        'fri' => [['start' => '09:00', 'end' => '18:00']],
        'sat' => [],
        'sun' => [],
    ];

    protected $fillable = [
        'tenant_id',
        'enabled',
        'mode',
        'criteria',
        'default_user_id',
        'timezone',
        'meeting_duration_minutes',
        'slot_interval_minutes',
        'min_notice_hours',
        'max_days_ahead',
        'working_hours',
    ];

    protected $attributes = [
        'enabled' => false,
        'mode' => 'approval',
        'timezone' => 'America/Argentina/Buenos_Aires',
        'meeting_duration_minutes' => 30,
        'slot_interval_minutes' => 30,
        'min_notice_hours' => 2,
        'max_days_ahead' => 14,
    ];

    public function __construct(array $attributes = [])
    {
        // Default no constante (JSON): las expresiones de $attributes no
        // admiten json_encode, y la UI necesita el default en un modelo nuevo.
        $this->attributes['working_hours'] = json_encode(self::DEFAULT_WORKING_HOURS);

        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'mode' => AiSchedulingMode::class,
            'working_hours' => 'array',
            'default_user_id' => 'integer',
            'meeting_duration_minutes' => 'integer',
            'slot_interval_minutes' => 'integer',
            'min_notice_hours' => 'integer',
            'max_days_ahead' => 'integer',
        ];
    }

    public function defaultUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_user_id');
    }
}
