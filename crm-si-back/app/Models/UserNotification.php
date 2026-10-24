<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notificación in-app de un usuario dentro de un workspace. Se crea vía
 * NotificationService y se empuja en vivo por el canal privado del usuario.
 */
class UserNotification extends Model
{
    use BelongsToTenant;
    use MassPrunable;

    protected $fillable = ['tenant_id', 'user_id', 'type', 'group_key', 'data', 'count', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'count' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    /** Leídas con más de 30 días o cualquiera con más de 90. */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->whereNotNull('read_at')->where('read_at', '<', now()->subDays(30)))
                ->orWhere('created_at', '<', now()->subDays(90)));
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'type' => $this->type,
            'group_key' => $this->group_key,
            'data' => $this->data ?? [],
            'count' => $this->count,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
