<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\Cache;

/**
 * Recuerda por unas horas que Meta no encontró los productos del catálogo de
 * un tenant, para que la IA no siga ofreciendo fichas que no se pueden enviar.
 * Un envío exitoso (por ejemplo, desde el botón del chat) lo vuelve a habilitar.
 */
class WhatsAppCatalogAvailability
{
    private const TTL_HOURS = 6;

    public function isAvailable(int $tenantId): bool
    {
        return ! Cache::has($this->key($tenantId));
    }

    public function markUnavailable(int $tenantId): void
    {
        Cache::put($this->key($tenantId), true, now()->addHours(self::TTL_HOURS));
    }

    public function markAvailable(int $tenantId): void
    {
        Cache::forget($this->key($tenantId));
    }

    private function key(int $tenantId): string
    {
        return "whatsapp-catalog-unavailable:{$tenantId}";
    }
}
