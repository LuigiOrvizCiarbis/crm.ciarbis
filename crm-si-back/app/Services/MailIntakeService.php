<?php

namespace App\Services;

use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MailChannelRule;
use App\Models\MailConfig;
use App\Models\MailIntake;
use App\Models\Message;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MailIntakeService
{
    public function capture(Channel $channel, MailConfig $config, array $attributes): MailIntake
    {
        $existing = MailIntake::where('external_id', $attributes['external_id'])->first();
        if ($existing) {
            return $existing;
        }

        [$status, $reason] = $this->classify($channel, $attributes);

        try {
            return MailIntake::create([
                ...$attributes,
                'tenant_id' => $channel->tenant_id,
                'channel_id' => $channel->id,
                'mail_config_id' => $config->id,
                'status' => $status,
                'classification_reason' => $reason,
                'expires_at' => $status === 'rejected' ? now()->addDays(30) : null,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return MailIntake::where('external_id', $attributes['external_id'])->firstOrFail();
            }

            throw $exception;
        }
    }

    /** @return array{string, string} */
    private function classify(Channel $channel, array $attributes): array
    {
        $email = strtolower($attributes['from_email']);
        $domain = Str::after($email, '@');
        $rules = MailChannelRule::query()->where('channel_id', $channel->id)->get();

        if ($this->matchesRule($rules, 'block', $email, $domain) || $this->isAutomatic($attributes)) {
            return ['rejected', $this->matchesRule($rules, 'block', $email, $domain) ? 'blocked_sender' : 'automatic_email'];
        }

        $threadIds = array_filter([...(array) ($attributes['in_reply_to'] ?? []), ...(array) ($attributes['references'] ?? [])]);
        if ($threadIds !== [] && Message::query()->where('tenant_id', $channel->tenant_id)->whereIn('mail_message_id', $threadIds)->exists()) {
            return ['accepted', 'existing_thread'];
        }

        if ($this->matchesRule($rules, 'allow', $email, $domain)) {
            return ['accepted', 'allowed_sender'];
        }

        $hasContact = Contact::query()
            ->where('tenant_id', $channel->tenant_id)
            ->whereRaw('lower(email) = ?', [$email])
            ->exists();

        return $hasContact ? ['accepted', 'existing_contact'] : ['pending', 'unknown_sender'];
    }

    private function matchesRule($rules, string $type, string $email, string $domain): bool
    {
        return $rules->contains(fn (MailChannelRule $rule) => $rule->type === $type
            && (($rule->value_type === 'email' && strtolower($rule->value) === $email)
                || ($rule->value_type === 'domain' && strtolower($rule->value) === $domain)));
    }

    private function isAutomatic(array $attributes): bool
    {
        $email = strtolower($attributes['from_email']);
        $headers = array_change_key_case((array) ($attributes['headers'] ?? []), CASE_LOWER);
        $autoSubmitted = strtolower((string) ($headers['auto-submitted'] ?? ''));
        $precedence = strtolower((string) ($headers['precedence'] ?? ''));

        return str_contains($email, 'no-reply') || str_contains($email, 'noreply')
            || ($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || in_array($precedence, ['bulk', 'list', 'junk'], true)
            || isset($headers['list-id']) || isset($headers['list-unsubscribe'])
            || in_array(strtolower((string) ($headers['x-spam-flag'] ?? '')), ['yes', 'true'], true);
    }

    public function approve(MailIntake $intake, User $user): Message
    {
        if ($intake->status === 'accepted' && $intake->acceptedMessage) {
            return $intake->acceptedMessage->load(['mailDetails', 'mailAttachments']);
        }

        return DB::transaction(function () use ($intake, $user): Message {
            $intake = MailIntake::query()->lockForUpdate()->findOrFail($intake->id);
            if ($intake->status === 'accepted' && $intake->acceptedMessage) {
                return $intake->acceptedMessage->load(['mailDetails', 'mailAttachments']);
            }

            $channel = $intake->channel()->firstOrFail();
            $contact = $this->findOrCreateContact($channel, $intake->from_email, $intake->from_name);
            $conversation = Conversation::firstOrCreate(
                ['tenant_id' => $channel->tenant_id, 'contact_id' => $contact->id, 'channel_id' => $channel->id],
                ['status' => 'open', 'last_message_at' => now(), 'branch_id' => $contact->branch_id ?? $channel->branch_id, 'ai_autoreply_enabled' => false],
            );

            if (! $conversation->pipeline_stage_id) {
                $defaultStage = PipelineStage::query()->where('tenant_id', $channel->tenant_id)
                    ->orderByDesc('is_default')->orderBy('sort_order')->first();
                if ($defaultStage) {
                    $conversation->update(['pipeline_stage_id' => $defaultStage->id]);
                }
            }

            $message = Message::create([
                'tenant_id' => $channel->tenant_id,
                'conversation_id' => $conversation->id,
                'sender_type' => SenderType::CONTACT,
                'sender_id' => $contact->id,
                'content' => $intake->body_text ?: ($intake->subject ?: ''),
                'message_type' => MessageType::Text,
                'direction' => MessageDirection::INBOUND,
                'external_id' => $intake->external_id,
                'mail_message_id' => $intake->mail_message_id,
                'delivered_at' => $intake->received_at ?? now(),
            ]);

            $message->mailDetails()->create([
                'subject' => $intake->subject,
                'body_text' => $intake->body_text,
                'body_html' => $intake->body_html,
                'from' => ['email' => $intake->from_email, 'name' => $intake->from_name],
                'to' => $intake->to ?? [], 'cc' => $intake->cc ?? [], 'bcc' => $intake->bcc ?? [],
                'reply_to' => $intake->reply_to, 'in_reply_to' => $intake->in_reply_to ?? [],
                'references' => $intake->references ?? [], 'has_remote_images' => $intake->has_remote_images,
            ]);

            foreach ($intake->attachments ?? [] as $index => $attachment) {
                Message::create([
                    'tenant_id' => $channel->tenant_id, 'conversation_id' => $conversation->id,
                    'sender_type' => SenderType::CONTACT, 'sender_id' => $contact->id, 'content' => '',
                    'message_type' => MessageType::from($attachment['type']), 'media_url' => $attachment['url'],
                    'media_mime_type' => $attachment['mime_type'], 'media_filename' => $attachment['filename'],
                    'direction' => MessageDirection::INBOUND, 'external_id' => $intake->external_id.'-att-'.($index + 1),
                    'mail_parent_message_id' => $message->id, 'delivered_at' => $intake->received_at ?? now(),
                ]);
            }

            $conversation->update([
                'last_message_at' => $message->created_at,
                'last_message_content' => Str::limit(trim(implode(' · ', array_filter([$intake->subject, $intake->body_text]))), 120),
            ]);
            $intake->update(['status' => 'accepted', 'classification_reason' => 'approved', 'accepted_message_id' => $message->id, 'decided_at' => now(), 'decided_by' => $user->id, 'expires_at' => null]);

            return $message->load(['mailDetails', 'mailAttachments']);
        });
    }

    public function reject(MailIntake $intake, User $user): MailIntake
    {
        $intake->update(['status' => 'rejected', 'classification_reason' => 'manual_rejection', 'decided_at' => now(), 'decided_by' => $user->id, 'expires_at' => now()->addDays(30)]);

        return $intake->fresh();
    }

    public function restore(MailIntake $intake): MailIntake
    {
        $intake->update(['status' => 'pending', 'classification_reason' => 'restored', 'decided_at' => null, 'decided_by' => null, 'expires_at' => null]);

        return $intake->fresh();
    }

    private function findOrCreateContact(Channel $channel, string $email, ?string $name): Contact
    {
        $contact = Contact::query()->where('tenant_id', $channel->tenant_id)->whereRaw('lower(email) = ?', [strtolower($email)])->first();
        if ($contact) {
            return $contact;
        }

        return Contact::firstOrCreate(
            ['tenant_id' => $channel->tenant_id, 'source' => 'mail', 'external_id' => strtolower($email)],
            ['name' => $name ?: Str::before($email, '@'), 'email' => $email, 'branch_id' => $channel->branch_id],
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return in_array((string) $exception->getCode(), ['23000', '23505'], true) || str_contains(strtolower($exception->getMessage()), 'unique constraint');
    }
}
