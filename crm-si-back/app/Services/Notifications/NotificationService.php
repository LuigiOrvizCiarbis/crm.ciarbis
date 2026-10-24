<?php

namespace App\Services\Notifications;

use App\Enums\NotificationType;
use App\Events\UserNotificationCreated;
use App\Models\User;
use App\Models\UserNotification;
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

    /** @param  array<string, mixed>  $data */
    private function upsert(int $userId, int $tenantId, NotificationType $type, array $data, ?string $groupKey): UserNotification
    {
        if ($groupKey !== null) {
            $existing = UserNotification::withoutGlobalScopes()
                ->where('user_id', $userId)
                ->where('tenant_id', $tenantId)
                ->where('type', $type->value)
                ->where('group_key', $groupKey)
                ->whereNull('read_at')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $existing->fill(['data' => $data, 'count' => $existing->count + 1])->save();

                return $existing;
            }
        }

        return UserNotification::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'type' => $type->value,
            'group_key' => $groupKey,
            'data' => $data,
        ]);
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
