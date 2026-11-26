<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\Cache;

/**
 * Recuerda por unas horas los productos que Meta todavía no habilitó en
 * WhatsApp, para no volver a ofrecerlos, y el último que aceptó, que sirve de
 * portada del catálogo.
 */
class WhatsAppCatalogAvailability
{
    private const TTL_HOURS = 6;

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

    /** Producto que Meta aceptó hace poco: sirve de miniatura para el mensaje de catálogo. */
    public function rememberWorkingProduct(int $tenantId, string $retailerId): void
    {
        Cache::forget($this->productKey($tenantId, $retailerId));
        Cache::put("whatsapp-catalog-working:{$tenantId}", $retailerId, now()->addDays(7));
    }

    public function workingProduct(int $tenantId): ?string
    {
        $retailerId = Cache::get("whatsapp-catalog-working:{$tenantId}");

        return is_string($retailerId) ? $retailerId : null;
    }

    private function productKey(int $tenantId, string $retailerId): string
    {
        return "whatsapp-catalog-missing:{$tenantId}:{$retailerId}";
    }
}
