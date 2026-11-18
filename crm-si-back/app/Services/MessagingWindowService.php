<?php

namespace App\Services;

use App\Enums\ChannelType;
use App\Exceptions\MetaApiException;
use App\Models\Conversation;
use App\Models\Message;
use Carbon\CarbonImmutable;

/**
 * Ventana de atención de Meta: único lugar que calcula hasta cuándo se le
 * puede escribir texto libre a un contacto.
 *
 * - Estándar: 24 h desde el último mensaje entrante (WhatsApp, Instagram, Messenger).
 * - Human Agent: 7 días desde ese mismo mensaje, sólo Instagram/Messenger, sólo
 *   para respuestas tipeadas por una persona y con el feature aprobado por Meta
 *   (config services.meta.human_agent_enabled).
 *
 * Los grupos de WhatsApp y los canales que no son de Meta no tienen ventana.
 */
class MessagingWindowService
{
    public const STANDARD_WINDOW_HOURS = 24;

    public const HUMAN_AGENT_WINDOW_DAYS = 7;

    public const HUMAN_AGENT_TAG = 'HUMAN_AGENT';

    private const WINDOWED_CHANNELS = [ChannelType::WHATSAPP, ChannelType::INSTAGRAM, ChannelType::FACEBOOK];

    private const HUMAN_AGENT_CHANNELS = [ChannelType::INSTAGRAM, ChannelType::FACEBOOK];

    public function applies(Conversation $conversation): bool
    {
        return in_array($conversation->channel?->type, self::WINDOWED_CHANNELS, true)
            && ! $conversation->isGroup();
    }

    /**
     * Hasta cuándo se puede escribir texto libre. Null si no aplica ventana o
     * si el contacto nunca escribió.
     */
    public function expiresAt(Conversation $conversation): ?CarbonImmutable
    {
        return $this->applies($conversation)
            ? $this->standardExpiry($this->lastInboundAt($conversation))
            : null;
    }

    public function humanAgentEnabled(Conversation $conversation): bool
    {
        return $this->applies($conversation)
            && in_array($conversation->channel?->type, self::HUMAN_AGENT_CHANNELS, true)
            && (bool) config('services.meta.human_agent_enabled');
    }

    /**
     * Hasta cuándo una persona puede responder con el tag HUMAN_AGENT. Null si
     * el tag no está habilitado para la conversación o el contacto nunca escribió.
     */
    public function humanAgentExpiresAt(Conversation $conversation): ?CarbonImmutable
    {
        return $this->humanAgentEnabled($conversation)
            ? $this->humanAgentExpiry($this->lastInboundAt($conversation))
            : null;
    }

    public function isOpen(Conversation $conversation): bool
    {
        return ! $this->applies($conversation)
            || $this->isStandardOpen($this->lastInboundAt($conversation));
    }

    /**
     * @throws MetaApiException si la ventana estándar está cerrada
     */
    public function assertOpen(Conversation $conversation): void
    {
        if (! $this->isOpen($conversation)) {
            throw MetaApiException::windowClosed($conversation->channel->type);
        }
    }

    /**
     * Tag a usar en un envío inmediato de una persona: null con la ventana
     * estándar abierta, HUMAN_AGENT si sólo sigue abierta la de 7 días.
     *
     * @throws MetaApiException si ninguna de las dos ventanas permite el envío
     */
    public function tagForHumanSend(Conversation $conversation): ?string
    {
        if (! $this->applies($conversation)) {
            return null;
        }

        $lastInboundAt = $this->lastInboundAt($conversation);

        if ($this->isStandardOpen($lastInboundAt)) {
            return null;
        }

        $humanAgentExpiresAt = $this->humanAgentEnabled($conversation)
            ? $this->humanAgentExpiry($lastInboundAt)
            : null;

        if ($humanAgentExpiresAt !== null && now()->lt($humanAgentExpiresAt)) {
            return self::HUMAN_AGENT_TAG;
        }

        throw MetaApiException::windowClosed($conversation->channel->type);
    }

    /**
     * @return array{applies: bool, expires_at: ?string, human_agent_enabled: bool, human_agent_expires_at: ?string}
     */
    public function toArray(Conversation $conversation): array
    {
        $applies = $this->applies($conversation);
        $humanAgentEnabled = $this->humanAgentEnabled($conversation);
        $lastInboundAt = $applies ? $this->lastInboundAt($conversation) : null;

        return [
            'applies' => $applies,
            'expires_at' => $this->standardExpiry($lastInboundAt)?->toIso8601String(),
            'human_agent_enabled' => $humanAgentEnabled,
            'human_agent_expires_at' => $humanAgentEnabled
                ? $this->humanAgentExpiry($lastInboundAt)?->toIso8601String()
                : null,
        ];
    }

    private function isStandardOpen(?CarbonImmutable $lastInboundAt): bool
    {
        $expiresAt = $this->standardExpiry($lastInboundAt);

        return $expiresAt !== null && now()->lt($expiresAt);
    }

    private function standardExpiry(?CarbonImmutable $lastInboundAt): ?CarbonImmutable
    {
        return $lastInboundAt?->addHours(self::STANDARD_WINDOW_HOURS);
    }

    private function humanAgentExpiry(?CarbonImmutable $lastInboundAt): ?CarbonImmutable
    {
        return $lastInboundAt?->addDays(self::HUMAN_AGENT_WINDOW_DAYS);
    }

    /**
     * Incluye mensajes borrados: el contacto igual escribió y Meta lo cuenta.
     */
    private function lastInboundAt(Conversation $conversation): ?CarbonImmutable
    {
        $lastInbound = Message::withTrashed()
            ->where('conversation_id', $conversation->id)
            ->inbound()
            ->max('created_at');

        return $lastInbound !== null ? CarbonImmutable::parse($lastInbound) : null;
    }
}
