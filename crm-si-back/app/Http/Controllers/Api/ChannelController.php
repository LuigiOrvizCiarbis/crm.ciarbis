<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChannelController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Channel::class);

        $user = $request->user();

        $channels = Channel::query()
            ->with('whatsappConfig', 'instagramConfig', 'facebookConfig', 'mailConfig', 'user', 'users')
            ->withCount(['conversations as conversations_count' => function (Builder $query) use ($user): void {
                $query->visibleTo($user)
                    ->where(function (Builder $inner): void {
                        $inner->whereColumn('conversations.channel_id', 'channels.id')
                            ->orWhereIn('conversations.assigned_to', function ($sub): void {
                                $sub->from('channel_user')
                                    ->select('user_id')
                                    ->whereColumn('channel_user.channel_id', 'channels.id');
                            })
                            ->orWhereIn('conversations.assigned_to', function ($sub): void {
                                $sub->from('channels as owner_channels')
                                    ->select('user_id')
                                    ->whereColumn('owner_channels.id', 'channels.id');
                            })
                            ->orWhereExists(function ($sub): void {
                                $sub->from('conversation_user')
                                    ->whereColumn('conversation_user.conversation_id', 'conversations.id')
                                    ->whereIn('conversation_user.user_id', function ($inner): void {
                                        $inner->from('channel_user')
                                            ->select('user_id')
                                            ->whereColumn('channel_user.channel_id', 'channels.id');
                                    });
                            });
                    });
            }])
            ->visibleTo($user)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $channels]);
    }

    public function users(Request $request, $id)
    {
        $channel = Channel::findOrFail($id);
        $this->authorize('view', $channel);

        $channel->load('users:id,name,email');

        return response()->json([
            'data' => $channel->users,
            'owner' => $channel->user,
        ]);
    }

    public function addUser(Request $request, $id)
    {
        $channel = Channel::findOrFail($id);
        $this->authorize('manageUsers', $channel);

        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $channel->users()->syncWithoutDetaching([$request->user_id]);

        return response()->json([
            'message' => 'Usuario agregado al canal',
            'users' => $channel->users()->get(['id', 'name', 'email']),
        ]);
    }

    public function removeUser(Request $request, $id, $userId)
    {
        $channel = Channel::findOrFail($id);
        $this->authorize('manageUsers', $channel);

        if ($channel->user_id == $userId) {
            return response()->json([
                'message' => 'No se puede remover al dueño principal del canal',
            ], 422);
        }

        $channel->users()->detach($userId);

        return response()->json([
            'message' => 'Usuario removido del canal',
            'users' => $channel->users()->get(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request, $id)
    {
        $channel = Channel::findOrFail($id);
        $this->authorize('update', $channel);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $channel->update(['name' => $validated['name']]);

        return response()->json(['data' => $channel->refresh()]);
    }

    public function assignBranch(Request $request, $id)
    {
        $channel = Channel::findOrFail($id);
        $this->authorize('update', $channel);

        $tenantId = $request->user()->tenant_id;

        if (! $request->user()->can('branches.manage')) {
            abort(403, 'No tienes permiso para asignar sucursales.');
        }

        $validated = $request->validate([
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
        ]);

        $channel->update(['branch_id' => $validated['branch_id'] ?? null]);

        return response()->json(['data' => $channel->refresh()]);
    }

    public function syncUsers(Request $request, $id)
    {
        $channel = Channel::findOrFail($id);
        $this->authorize('manageUsers', $channel);

        $request->validate([
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
        ]);

        $channel->users()->sync($request->user_ids);

        return response()->json([
            'message' => 'Usuarios sincronizados',
            'users' => $channel->users()->get(['id', 'name', 'email']),
        ]);
    }
}
