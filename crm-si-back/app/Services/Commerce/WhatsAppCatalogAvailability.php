<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\Cache;

/**
 * Recuerda por unas horas lo que Meta no pudo enviar del catálogo de un
 * tenant: productos puntuales todavía no habilitados en WhatsApp, o el
 * catálogo entero. Así la IA no sigue ofreciendo fichas que van a fallar. Un
 * envío exitoso del catálogo o de fichas vuelve a habilitar el catálogo.
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

    /** Meta no tiene habilitado ese producto en WhatsApp: no se vuelve a ofrecer por unas horas. */
    public function markProductMissing(int $tenantId, string $retailerId): void
    {
        Cache::put($this->productKey($tenantId, $retailerId), true, now()->addHours(self::TTL_HOURS));
    }

    /**
     * @param  list<string>  $retailerIds
     * @return list<string>
     */
    public function withoutMissingProducts(int $tenantId, array $retailerIds): array
    {
        return array_values(array_filter($retailerIds, fn (string $id) => ! Cache::has($this->productKey($tenantId, $id))));
    }

    private function key(int $tenantId): string
    {
        // Clave nueva: la anterior ("whatsapp-catalog-unavailable") también se
        // ponía por un solo producto rechazado y no debe seguir bloqueando.
        return "whatsapp-catalog-blocked:{$tenantId}";
    }

    private function productKey(int $tenantId, string $retailerId): string
    {
        return "whatsapp-catalog-missing:{$tenantId}:{$retailerId}";
    }
}
