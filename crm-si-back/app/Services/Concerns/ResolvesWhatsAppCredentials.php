<?php

namespace App\Services\Concerns;

use App\Enums\ChannelType;
use App\Models\Channel;

trait ResolvesWhatsAppCredentials
{
    /**
     * Resuelve token + phone_number_id de un canal de WhatsApp, sin asumir
     * contacto ni conversación. Compartido por los servicios que necesitan
     * pegarle a Graph API a nivel de canal (grupos, plantillas, mensajes).
     *
     * @return array{business_phone_id: string, business_token: string}
     */
    protected function resolveWhatsAppCredentials(Channel $channel): array
    {
        if ($channel->type !== ChannelType::WHATSAPP) {
            throw new \InvalidArgumentException('Solo se pueden enviar mensajes desde canales de WhatsApp.');
        }

        if (! $channel->isActive()) {
            throw new \InvalidArgumentException('El canal de WhatsApp está desconectado.');
        }

        $waConfig = $channel->whatsappConfig;
        if (! $waConfig || ! $waConfig->phone_number_id) {
            throw new \InvalidArgumentException('El canal no tiene una configuración válida de WhatsApp.');
        }

        $businessToken = $waConfig->getDecryptedToken();
        if (! $businessToken) {
            throw new \InvalidArgumentException('No se pudo obtener el token de WhatsApp del canal.');
        }

        return [
            'business_phone_id' => $waConfig->phone_number_id,
            'business_token' => $businessToken,
        ];
    }
}
