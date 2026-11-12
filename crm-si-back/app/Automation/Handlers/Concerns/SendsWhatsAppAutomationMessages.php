<?php

namespace App\Automation\Handlers\Concerns;

use App\Automation\AutomationContext;
use App\Automation\Exceptions\ActionSkippedException;
use App\Automation\Exceptions\RetryableActionException;
use App\Models\AutomationRun;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PipelineStage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Piezas compartidas por las acciones que envían mensajes de WhatsApp: el
 * contacto del run, la conversación del canal y el límite de envíos por número.
 *
 * @property AutomationContext $context
 */
trait SendsWhatsAppAutomationMessages
{
    private function contact(AutomationRun $run): Contact
    {
        $context = $this->context->forRun($run);
        $contactId = Arr::get($context, 'contact.id');
        $contact = Contact::withoutGlobalScopes()->where('tenant_id', $run->tenant_id)->find($contactId);
        if (! $contact) {
            throw new ActionSkippedException('contact_not_found');
        }

        return $contact;
    }

    private function conversationFor(AutomationRun $run, Contact $contact, Channel $channel): Conversation
    {
        return Conversation::firstOrCreate(
            ['tenant_id' => $run->tenant_id, 'contact_id' => $contact->id, 'channel_id' => $channel->id],
            [
                'status' => 'open',
                'branch_id' => $contact->branch_id ?? $channel->branch_id,
                'pipeline_stage_id' => PipelineStage::where('tenant_id', $run->tenant_id)->whereHas('pipeline', fn ($query) => $query->where('is_default', true))->orderByDesc('is_default')->orderBy('sort_order')->value('id'),
                'ai_autoreply_enabled' => (bool) $channel->whatsappConfig?->ai_autoreply_default,
            ],
        );
    }

    private function throttleWhatsApp(Channel $channel): void
    {
        if (! RateLimiter::attempt("automation-whatsapp:{$channel->whatsapp_config_id}", 80, fn () => true, 60)) {
            throw new RetryableActionException('whatsapp_rate_limit');
        }
    }

    /**
     * Un 429 o 5xx de Meta se reintenta; cualquier otro error se propaga.
     */
    private function rethrowSendFailure(\RuntimeException $exception): never
    {
        if (str_contains($exception->getMessage(), '429') || preg_match('/\b5\d\d\b/', $exception->getMessage())) {
            throw new RetryableActionException($exception->getMessage(), previous: $exception);
        }

        throw $exception;
    }
}
