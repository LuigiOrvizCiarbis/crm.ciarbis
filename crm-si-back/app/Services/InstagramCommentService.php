<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InstagramComment;
use App\Models\InstagramConfig;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramCommentService
{
    public function processWebhook(string $entryId, array $value): ?InstagramComment
    {
        $externalId = $value['id'] ?? $value['comment_id'] ?? null;
        $from = $value['from'] ?? [];
        if (!$externalId || empty($from['id']) || (string) $from['id'] === (string) $entryId) return null;

        $channel = $this->resolveChannel($entryId);
        if (!$channel) return null;

        $commentedAt = isset($value['created_time']) ? now()->setTimestamp((int) $value['created_time']) : now();
        $data = [
            'tenant_id' => $channel->tenant_id,
            'channel_id' => $channel->id,
            'external_id' => (string) $externalId,
            'parent_external_id' => $value['parent_id'] ?? null,
            'author_external_id' => (string) $from['id'],
            'author_username' => $from['username'] ?? null,
            'text' => $value['text'] ?? null,
            'media_id' => data_get($value, 'media.id'),
            'media_product_type' => data_get($value, 'media.media_product_type'),
            'ad_id' => data_get($value, 'media.ad_id'),
            'ad_title' => data_get($value, 'media.ad_title'),
            'commented_at' => $commentedAt,
            'private_reply_deadline' => $commentedAt->copy()->addDays(7),
        ];

        try {
            return InstagramComment::firstOrCreate(['external_id' => $data['external_id']], $data);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) return InstagramComment::where('external_id', $externalId)->first();
            throw $e;
        }
    }

    public function replyPublicly(InstagramComment $comment, string $text, User $user): InstagramComment
    {
        $response = $this->request($comment, 'post', "{$comment->external_id}/replies", ['message' => $text]);
        $comment->update(['status' => 'resolved', 'last_action_at' => now()]);
        Log::info('Instagram public comment reply sent', ['comment_id' => $comment->id, 'meta_id' => $response['id'] ?? null, 'user_id' => $user->id]);
        return $comment->fresh();
    }

    public function replyPrivately(InstagramComment $comment, string $text, User $user): InstagramComment
    {
        if (!$comment->privateReplyAvailable()) throw new \InvalidArgumentException('La respuesta privada ya fue utilizada o la ventana de 7 días expiró.');
        $config = $comment->channel?->instagramConfig;
        $response = $this->request($comment, 'post', "{$config->ig_user_id}/messages", [
            'recipient' => ['comment_id' => $comment->external_id],
            'message' => ['text' => $text],
        ]);
        $this->linkConversation($comment);
        $comment->update([
            'status' => 'in_progress', 'private_replied_at' => now(),
            'private_reply_external_id' => $response['message_id'] ?? null, 'last_action_at' => now(),
        ]);
        Log::info('Instagram private comment reply sent', ['comment_id' => $comment->id, 'user_id' => $user->id]);
        return $comment->fresh(['contact', 'conversation']);
    }

    public function setVisibility(InstagramComment $comment, bool $hidden, User $user): InstagramComment
    {
        $this->request($comment, 'post', $comment->external_id, ['hide' => $hidden]);
        $comment->update(['visibility' => $hidden ? 'hidden' : 'visible', 'last_action_at' => now()]);
        Log::info('Instagram comment visibility changed', ['comment_id' => $comment->id, 'hidden' => $hidden, 'user_id' => $user->id]);
        return $comment->fresh();
    }

    public function delete(InstagramComment $comment, User $user): InstagramComment
    {
        $this->request($comment, 'delete', $comment->external_id);
        $comment->update(['visibility' => 'deleted', 'status' => 'resolved', 'last_action_at' => now()]);
        Log::warning('Instagram comment deleted', ['comment_id' => $comment->id, 'user_id' => $user->id]);
        return $comment->fresh();
    }

    private function resolveChannel(string $entryId): ?Channel
    {
        return InstagramConfig::where(fn ($q) => $q->where('webhook_object_id', $entryId)->orWhere('ig_user_id', $entryId))->with('channels')->first()?->channels->first();
    }

    private function linkConversation(InstagramComment $comment): void
    {
        $channel = $comment->channel;
        $contact = Contact::firstOrCreate([
            'tenant_id' => $channel->tenant_id, 'source' => 'instagram', 'external_id' => $comment->author_external_id,
        ], ['name' => $comment->author_username ?: 'Instagram '.substr($comment->author_external_id, -6)]);
        $conversation = Conversation::firstOrCreate([
            'tenant_id' => $channel->tenant_id, 'channel_id' => $channel->id, 'contact_id' => $contact->id,
        ], ['status' => 'open', 'last_message_at' => now()]);
        $comment->update(['contact_id' => $contact->id, 'conversation_id' => $conversation->id]);
    }

    private function request(InstagramComment $comment, string $method, string $path, ?array $payload = null): array
    {
        $token = $comment->channel?->instagramConfig?->getDecryptedToken();
        if (!$token) throw new \InvalidArgumentException('El canal de Instagram no tiene un token válido.');
        $version = config('services.facebook.graph_version', 'v21.0');
        $url = "https://graph.facebook.com/{$version}/{$path}";
        $response = Http::withToken($token)->timeout(15)->{$method}($url, $payload ?? []);
        if (!$response->successful()) throw new \RuntimeException($response->json('error.message') ?: 'Meta rechazó la operación.');
        return $response->json() ?: [];
    }
}
