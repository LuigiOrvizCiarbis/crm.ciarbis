<?php

namespace App\Http\Controllers\Api;

use App\Events\UserNotificationsRead;
use App\Http\Controllers\Controller;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Bandeja de notificaciones in-app del usuario autenticado en el workspace
 * activo. Sin permisos Spatie: cada usuario sólo opera sobre las suyas.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'unread' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $page = $this->query($request)
            ->when($request->boolean('unread'), fn (Builder $query) => $query->unread())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => collect($page->items())->map->payload()->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'unread_count' => $this->countUnread($request),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->countUnread($request)]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = $this->query($request)->findOrFail($id);
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $this->readResponse($request, [$notification->id]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $this->query($request)->unread()->update(['read_at' => now()]);

        return $this->readResponse($request, null);
    }

    public function markGroupRead(Request $request): JsonResponse
    {
        $validated = $request->validate(['group_key' => ['required', 'string', 'max:120']]);

        $ids = $this->query($request)->unread()->where('group_key', $validated['group_key'])->pluck('id')->all();
        if ($ids !== []) {
            UserNotification::withoutGlobalScopes()->whereKey($ids)->update(['read_at' => now()]);
        }

        return $this->readResponse($request, $ids);
    }

    private function countUnread(Request $request): int
    {
        return $this->query($request)->unread()->count();
    }

    private function query(Request $request): Builder
    {
        $user = $request->user();

        return UserNotification::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('tenant_id', $user->tenant_id);
    }

    /** @param  list<int>|null  $ids */
    private function readResponse(Request $request, ?array $ids): JsonResponse
    {
        $user = $request->user();
        $unread = $this->countUnread($request);

        if ($ids === null || $ids !== []) {
            try {
                broadcast(new UserNotificationsRead($user->id, (int) $user->tenant_id, $ids, $unread))->toOthers();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['unread_count' => $unread]);
    }
}
