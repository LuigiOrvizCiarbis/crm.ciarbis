<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Events\UserNotificationCreated;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Punto único para crear notificaciones in-app. Persiste por usuario+workspace,
 * agrupa por `group_key` mientras la notificación siga sin leer y la empuja por
 * Reverb al canal privado del usuario.
 */
class NotificationService
{
    /**
     * @param  iterable<User>  $users
     * @param  array<string, mixed>  $data
     */
    public function notify(
        iterable $users,
        int $tenantId,
        NotificationType $type,
        array $data,
        ?string $groupKey = null,
        ?int $exceptUserId = null,
    ): void {
        $seen = [];
        foreach ($users as $user) {
            if (isset($seen[$user->id]) || $user->id === $exceptUserId || ! $user->wantsNotification($type)) {
                continue;
            }
            $seen[$user->id] = true;

            $notification = DB::transaction(fn () => $this->upsert($user->id, $tenantId, $type, $data, $groupKey));

            DB::afterCommit(fn () => $this->broadcast($notification));
        }
    }

    /**
     * Con group_key, un índice único parcial (una sola fila no leída por
     * usuario/workspace/tipo/grupo) serializa la creación: si dos jobs
     * concurrentes no encuentran fila y ambos insertan, el segundo choca con
     * el índice y se reintenta como incremento.
     *
     * @param  array<string, mixed>  $data
     */
    private function upsert(int $userId, int $tenantId, NotificationType $type, array $data, ?string $groupKey): UserNotification
    {
        $attributes = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => $type->value,
            'group_key' => $groupKey,
        ];

        if ($groupKey === null) {
            return UserNotification::withoutGlobalScopes()->create([...$attributes, 'data' => $data]);
        }

        if ($existing = $this->lockUnreadGroup($attributes)) {
            return $this->increment($existing, $data);
        }

        try {
            // Transacción anidada = savepoint: en Postgres un insert fallido
            // aborta la transacción entera si no se revierte sólo el savepoint.
            return DB::transaction(fn () => UserNotification::withoutGlobalScopes()->create([...$attributes, 'data' => $data]));
        } catch (UniqueConstraintViolationException) {
            return $this->increment($this->lockUnreadGroup($attributes) ?? throw new \RuntimeException('Grouped notification vanished'), $data);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    private function lockUnreadGroup(array $attributes): ?UserNotification
    {
        return UserNotification::withoutGlobalScopes()
            ->where($attributes)
            ->whereNull('read_at')
            ->lockForUpdate()
            ->first();
    }

    /** @param  array<string, mixed>  $data */
    private function increment(UserNotification $notification, array $data): UserNotification
    {
        $notification->fill(['data' => $data, 'count' => $notification->count + 1])->save();

        return $notification;
    }

    private function broadcast(UserNotification $notification): void
    {
        // La notificación ya quedó persistida: si Reverb está caído el usuario
        // la ve al refrescar, así que no hacemos fallar el flujo que la originó.
        try {
            broadcast(new UserNotificationCreated($notification));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
