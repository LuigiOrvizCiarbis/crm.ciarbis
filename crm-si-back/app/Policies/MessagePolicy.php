<?php

namespace App\Policies;

use App\Enums\SenderType;
use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function __construct(private ConversationPolicy $conversations) {}

    public function viewAny(User $user): bool
    {
        return $user->can('messages.view');
    }

    public function view(User $user, Message $message): bool
    {
        if (! $this->sameTenant($user, $message)) {
            return false;
        }

        if (! $user->can('messages.view')) {
            return false;
        }

        $conversation = $message->conversation;

        return $conversation === null
            ? false
            : $this->conversations->view($user, $conversation);
    }

    public function create(User $user): bool
    {
        return $user->can('messages.create');
    }

    public function update(User $user, Message $message): bool
    {
        if (! $this->sameTenant($user, $message)) {
            return false;
        }

        if ($user->can('messages.update_any')) {
            return true;
        }

        if (! $user->can('messages.update')) {
            return false;
        }

        return $this->isOwnMessage($user, $message);
    }

    public function delete(User $user, Message $message): bool
    {
        if (! $this->sameTenant($user, $message)) {
            return false;
        }

        if ($user->can('messages.delete_any')) {
            return true;
        }

        if (! $user->can('messages.delete')) {
            return false;
        }

        return $this->isOwnMessage($user, $message);
    }

    /**
     * Reaccionar es enviar algo al contacto: se autoriza con el mismo
     * permiso que mandar un mensaje (conversations.send_message), no con
     * messages.update (que es "editar lo mío"). No hay un permiso dedicado
     * a propósito: quien puede escribir en la conversación puede reaccionar.
     */
    public function react(User $user, Message $message): bool
    {
        if (! $this->sameTenant($user, $message)) {
            return false;
        }

        $conversation = $message->conversation;

        return $conversation !== null
            && $this->conversations->sendMessage($user, $conversation);
    }

    /**
     * Message does not use the BelongsToTenant global scope, so route model
     * binding can resolve cross-tenant messages. Enforce tenancy here so the
     * delete_any/update_any short-circuits never apply across tenants.
     */
    private function sameTenant(User $user, Message $message): bool
    {
        return (int) $message->tenant_id === (int) $user->tenant_id;
    }

    private function isOwnMessage(User $user, Message $message): bool
    {
        return $message->sender_type === SenderType::USER
            && (int) $message->sender_id === (int) $user->id;
    }
}
