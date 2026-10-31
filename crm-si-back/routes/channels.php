<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\BroadcastCampaign;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversations.{conversationId}', function ($user, $conversationId) {
    return (int) $user->tenant_id === (int) Conversation::find($conversationId)?->tenant_id;
});

Broadcast::channel('messages.{messageId}', function (User $user, int $messageId) {
    return $user->id === Message::findOrNew($messageId)->user_id;
});

Broadcast::channel('tenant.{tenantId}', function (User $user, int $tenantId) {
    return (int) $user->tenant_id === $tenantId;
});

Broadcast::channel('broadcasts.{campaignId}', function (User $user, int $campaignId) {
    return $user->can('templates.view')
        && (int) $user->tenant_id === (int) BroadcastCampaign::withoutGlobalScopes()->whereKey($campaignId)->value('tenant_id');
});
