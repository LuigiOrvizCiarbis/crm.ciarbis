<?php

namespace App\Models;

use App\Enums\ContactFieldType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranch;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $external_id
 * @property string|null $source
 * @property array<string, mixed> $custom_data
 */
class Contact extends Model
{
    use BelongsToTenant;
    use HasBranch;
    use HasTags;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'phone',
        'email',
        'external_id',
        'source',
        'custom_data',
        'lock_version',
    ];

    protected $attributes = [
        'custom_data' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'custom_data' => 'array',
            'lock_version' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    public function scopeWithEmail($query)
    {
        return $query->whereNotNull('email');
    }

    public function scopeWithPhone($query)
    {
        return $query->whereNotNull('phone');
    }

    public function scopeWhereCustomField(Builder $query, string $key, mixed $value): Builder
    {
        if (is_array($value)) {
            return $query->whereJsonContains("custom_data->{$key}", $value);
        }

        $stringValue = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

        return $query->whereRaw('custom_data ->> ? = ?', [$key, $stringValue]);
    }

    /**
     * Filtra por un campo custom de tipo Date o Number dentro de un rango
     * inclusivo. `$type` decide el cast: los campos Date se comparan como
     * texto ISO (ya normalizados en escritura por ContactCustomDataNormalizer,
     * así el mismo SQL corre igual en el SQLite de los tests y el Postgres de
     * producción); los Number se castean a numeric porque se guardan como
     * número real en el JSON y comparar como texto ordenaría "9" > "10".
     * `$from`/`$to` llegan ya validados por el controller contra el tipo del
     * campo — acá no se revalida el formato.
     */
    public function scopeWhereCustomFieldRange(Builder $query, string $key, ContactFieldType $type, ?string $from, ?string $to): Builder
    {
        if ($from === null && $to === null) {
            return $query;
        }

        if ($type === ContactFieldType::Number) {
            // CAST(x AS REAL) es portable entre el SQLite de los tests y el
            // Postgres de producción (a diferencia de ::numeric, solo Postgres).
            // Solo Postgres explota con un valor no numérico ("N/A" cargado a
            // mano); se excluye ANTES del cast (mismo patrón que
            // ConversationController::L98 para ilike/like según el driver).
            // SQLite castea silenciosamente a 0 en vez de fallar, y 0 cae
            // fuera de cualquier rango de vencimiento o contador real, así
            // que ahí no hace falta excluir nada aparte.
            if ($query->getConnection()->getDriverName() === 'pgsql') {
                $query->whereRaw("custom_data ->> ? ~ '^-?[0-9]+(\\.[0-9]+)?$'", [$key]);
            }
            $column = 'CAST(custom_data ->> ? AS REAL)';
        } else {
            $column = 'custom_data ->> ?';
        }

        if ($from !== null) {
            $query->whereRaw("{$column} >= ?", [$key, $from]);
        }
        if ($to !== null) {
            $query->whereRaw("{$column} <= ?", [$key, $to]);
        }

        return $query;
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('contacts.view_any')) {
            return $query;
        }

        if (! $user->can('contacts.view_assigned')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->whereHas('conversations', fn (Builder $sub) => $sub->visibleTo($user))
                ->orWhereHas('opportunities', fn (Builder $sub) => $sub->where('assigned_to', $user->id));
        });
    }

    public function activeConversation()
    {
        return $this->conversations()->where('status', 'open')->latest()->first();
    }

    public function hasEmail(): bool
    {
        return ! is_null($this->email);
    }

    public function hasPhone(): bool
    {
        return ! is_null($this->phone);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name ?: $this->phone ?: $this->email ?: 'Sin nombre';
    }

    public function messages(): MorphMany
    {
        return $this->morphMany(Message::class, 'sender');
    }

    /**
     * Avanza lock_version en cada modificación persistida.
     *
     * Vive acá y no en cada writer a propósito: el contacto se escribe desde el
     * CRUD, los webhooks entrantes, el import de CSV y la confirmación de una
     * extracción. Si el contador sólo lo tocara quien lo lee, una edición hecha
     * por cualquiera de las otras vías dejaría la versión igual y la
     * confirmación de una extracción pisaría ese cambio sin devolver 409, que
     * es justo lo que el contador viene a evitar.
     *
     * Se excluye el caso en que lock_version ya venga modificado en el mismo
     * save (la confirmación lo incrementa explícitamente), para no contarlo dos
     * veces.
     */
    protected static function booted(): void
    {
        static::updating(function (self $contact): void {
            if ($contact->isDirty('lock_version')) {
                return;
            }

            if ($contact->getDirty() === []) {
                return;
            }

            $contact->lock_version = (int) $contact->getOriginal('lock_version') + 1;
        });
    }
}
