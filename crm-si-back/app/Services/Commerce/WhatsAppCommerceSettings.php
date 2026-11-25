<?php

namespace App\Services\Commerce;

use App\Models\WhatsAppConfig;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Muestra el catálogo y habilita el carrito en el número de WhatsApp, para
 * que el cliente pueda armar y enviar pedidos desde el chat.
 */
class WhatsAppCommerceSettings
{
    public function enable(WhatsAppConfig $config): void
    {
        $token = $config->getDecryptedToken();

        if (! $token) {
            throw new RuntimeException('No se pudo desencriptar el token de WhatsApp');
        }

        $query = http_build_query(['is_cart_enabled' => 'true', 'is_catalog_visible' => 'true']);
        $response = Http::withToken($token)
            ->timeout(15)
            ->post('https://graph.facebook.com/'.config('services.facebook.graph_version', 'v26.0')."/{$config->phone_number_id}/whatsapp_commerce_settings?{$query}");

        if (! $response->successful()) {
            throw new RuntimeException('Meta rechazó la configuración del catálogo: '.$response->body());
        }
    }
}
