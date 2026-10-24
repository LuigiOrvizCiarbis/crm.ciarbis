<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Sends the native typing actions supported by each connected channel. */
class AiTypingIndicatorTransport
{
    public function show(Conversation $conversation, Message $message): bool
    {
        if (! $conversation->channel?->isActive()) {
            return false;
        }

        return match ($conversation->channel->type) {
            ChannelType::WHATSAPP => $this->sendWhatsApp($conversation, $message),
            ChannelType::FACEBOOK, ChannelType::INSTAGRAM => $this->sendMetaAction($conversation, 'typing_on'),
            default => false,
        };
    }

    public function hide(Conversation $conversation): void
    {
        if (in_array($conversation->channel?->type, [ChannelType::FACEBOOK, ChannelType::INSTAGRAM], true)) {
            $this->sendMetaAction($conversation, 'typing_off');
        }
        // WhatsApp clears typing on delivery, or when its native timer expires.
    }

    private function sendWhatsApp(Conversation $conversation, Message $message): bool
    {
        $config = $conversation->channel?->whatsappConfig;
        $token = $config?->getDecryptedToken();
        if (! $config?->phone_number_id || ! $token || ! $message->external_id) {
            return false;
        }

        $response = Http::withToken($token)->timeout(3)->post(
            'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0')."/{$config->phone_number_id}/messages",
            [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $message->external_id,
                'typing_indicator' => ['type' => 'text'],
            ]
        );

        if (! $response->successful()) {
            Log::warning('WhatsApp typing indicator falló', [
                'conversation_id' => $conversation->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->successful();
    }

    private function sendMetaAction(Conversation $conversation, string $action): bool
    {
        $channel = $conversation->channel;
        $config = match ($channel?->type) {
            ChannelType::FACEBOOK => $channel->facebookConfig,
            ChannelType::INSTAGRAM => $channel->instagramConfig,
            default => null,
        };
        $pageId = $config?->page_id;
        $token = $config?->getDecryptedToken();
        $recipientId = $conversation->contact?->external_id;
        if (! $channel?->isActive() || ! $pageId || ! $token || ! $recipientId) {
            return false;
        }

        $response = Http::withToken($token)->timeout(3)->post(
            'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0')."/{$pageId}/messages",
            ['recipient' => ['id' => $recipientId], 'sender_action' => $action]
        );

        if (! $response->successful()) {
            Log::warning('Meta typing action falló', [
                'conversation_id' => $conversation->id,
                'channel_type' => $channel->type->value,
                'action' => $action,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return $response->successful();
    }
}
