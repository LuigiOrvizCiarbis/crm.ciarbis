<?php

namespace App\Services\Commerce;

use App\Models\WooCommerceConfig;
use App\Services\WooCommerceService;
use App\Services\WooCommerceSyncException;
use App\Services\WooCommerceUrlException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Registra en la tienda los webhooks que mantienen al día el catálogo del CRM
 * y el estado de los pedidos de WhatsApp. Re-registrar reemplaza los anteriores.
 */
class WooCommerceWebhookRegistrar
{
    public const TOPICS = ['product.created', 'product.updated', 'product.deleted', 'product.restored', 'order.updated'];

    public function __construct(private readonly WooCommerceService $woo) {}

    /**
     * @throws WooCommerceSyncException|WooCommerceUrlException
     */
    public function register(WooCommerceConfig $config): void
    {
        $client = $this->woo->client($config);

        if ($config->webhook_token === null) {
            $config->webhook_token = Str::random(48);
        }
        $secret = Str::random(40);

        foreach ($config->webhook_ids ?? [] as $id) {
            $response = $client->delete("/webhooks/{$id}?force=true");
            if ($response->status() !== 404) {
                $this->assertSuccessful($response);
            }
        }

        // Los viejos ya no existen y el secreto nuevo tiene que estar guardado
        // antes de que la tienda empiece a firmar con él.
        $config->webhook_ids = [];
        $config->setEncryptedWebhookSecret($secret);
        $config->save();

        $deliveryUrl = rtrim((string) config('app.url'), '/').'/api/woocommerce-webhook/'.$config->webhook_token;
        $ids = [];

        foreach (self::TOPICS as $topic) {
            $response = $client->post('/webhooks', [
                'name' => "CRM — {$topic}",
                'topic' => $topic,
                'status' => 'active',
                'secret' => $secret,
                'delivery_url' => $deliveryUrl,
            ]);
            $this->assertSuccessful($response);
            // Uno por uno: si falla a mitad, el próximo registro borra los ya creados.
            $ids[] = (int) $response->json('id');
            $config->webhook_ids = $ids;
            $config->save();
        }
    }

    /**
     * @throws WooCommerceSyncException
     */
    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new WooCommerceSyncException(in_array($response->status(), [401, 403], true)
            ? 'La clave de WooCommerce necesita permisos de Lectura/Escritura para crear los webhooks.'
            : "WooCommerce respondió con un error ({$response->status()}) al crear los webhooks.");
    }
}
