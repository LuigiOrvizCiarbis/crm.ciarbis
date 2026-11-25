<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Config de WooCommerce por tenant: URL de la tienda + Consumer Key/Secret de la
 * REST API (encriptados). Con estas credenciales se importan/sincronizan los
 * productos de la tienda al catálogo (source='woocommerce').
 *
 * @property string $store_url
 * @property bool $enabled
 * @property ?Carbon $last_synced_at
 * @property ?string $sync_status
 * @property ?string $sync_run_id
 * @property ?Carbon $sync_started_at
 * @property ?Carbon $sync_heartbeat_at
 * @property ?Carbon $sync_finished_at
 * @property ?array<string, mixed> $sync_result
 * @property ?string $sync_error
 * @property bool $whatsapp_checkout_enabled
 * @property ?string $meta_catalog_id
 * @property string $catalog_retailer_id_format
 * @property ?string $tracking_meta_key
 * @property ?string $webhook_token
 * @property ?list<int> $webhook_ids
 * @property ?int $paid_template_id
 * @property ?int $shipped_template_id
 */
class WooCommerceConfig extends Model
{
    use BelongsToTenant;

    public const SYNC_RUNNING = 'running';

    public const SYNC_COMPLETED = 'completed';

    public const SYNC_FAILED = 'failed';

    public const RETAILER_ID_PRODUCT_ID = 'product_id';

    public const RETAILER_ID_SKU = 'sku';

    protected $table = 'woocommerce_configs';

    // consumer_key/consumer_secret NO van acá a propósito: se escriben solo vía
    // setEncryptedConsumerKey/Secret (encriptan). Dejarlas fillable permitiría
    // guardarlas en plano por create()/fill()/update().
    protected $fillable = [
        'tenant_id',
        'store_url',
        'enabled',
        'last_synced_at',
        'sync_status',
        'sync_run_id',
        'sync_started_at',
        'sync_heartbeat_at',
        'sync_finished_at',
        'sync_result',
        'sync_error',
        'whatsapp_checkout_enabled',
        'meta_catalog_id',
        'catalog_retailer_id_format',
        'tracking_meta_key',
        'webhook_token',
        'webhook_ids',
        'paid_template_id',
        'shipped_template_id',
    ];

    protected $hidden = [
        'consumer_key',
        'consumer_secret',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'sync_started_at' => 'datetime',
            'sync_heartbeat_at' => 'datetime',
            'sync_finished_at' => 'datetime',
            'sync_result' => 'array',
            'whatsapp_checkout_enabled' => 'boolean',
            'webhook_ids' => 'array',
        ];
    }

    public function getDecryptedConsumerKey(): ?string
    {
        return $this->decrypt($this->consumer_key);
    }

    public function getDecryptedConsumerSecret(): ?string
    {
        return $this->decrypt($this->consumer_secret);
    }

    public function setEncryptedConsumerKey(string $value): void
    {
        $this->consumer_key = Crypt::encryptString($value);
    }

    public function setEncryptedConsumerSecret(string $value): void
    {
        $this->consumer_secret = Crypt::encryptString($value);
    }

    public function getDecryptedWebhookSecret(): ?string
    {
        return $this->decrypt($this->webhook_secret);
    }

    public function setEncryptedWebhookSecret(string $value): void
    {
        $this->webhook_secret = Crypt::encryptString($value);
    }

    /**
     * Lista para vender por WhatsApp: activada, con credenciales de Woo, ID del
     * catálogo de Meta y Mercado Pago configurado en el tenant.
     */
    public function checkoutReady(): bool
    {
        return $this->whatsapp_checkout_enabled
            && $this->hasCredentials()
            && ! empty($this->meta_catalog_id)
            && (bool) MercadoPagoConfig::withoutGlobalScopes()
                ->where('tenant_id', $this->tenant_id)
                ->first()?->hasCredentials();
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->consumer_key) && ! empty($this->consumer_secret);
    }

    /**
     * Desencripta un valor; loguea y devuelve null si falla (mismo criterio que AiConfig).
     */
    private function decrypt(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            Log::error('Error decrypting WooCommerce credential for tenant '.$this->tenant_id, [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
