<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Cuenta de vendedor de Mercado Libre del tenant. Los tokens se guardan
 * encriptados y nunca se exponen. Al desconectar se borran los tokens pero se
 * conserva la cuenta: si se reconecta la misma, sus publicaciones se retoman.
 *
 * @property int $id
 * @property int $tenant_id
 * @property ?int $connected_by
 * @property string $status
 * @property int $ml_user_id
 * @property ?string $nickname
 * @property string $site_id
 * @property string $currency_id
 * @property bool $user_products
 * @property ?Carbon $token_expires_at
 * @property ?Carbon $connected_at
 * @property bool $sync_enabled
 * @property string $listing_type_id
 * @property string $condition
 * @property int $default_quantity
 * @property ?Carbon $last_full_sync_at
 */
class MercadoLibreConnection extends Model
{
    use BelongsToTenant;

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const LISTING_TYPES = ['gold_special', 'gold_pro', 'free'];

    public const CONDITIONS = ['new', 'used'];

    protected $table = 'mercadolibre_connections';

    // Los tokens NO son fillable: solo se escriben encriptados.
    protected $fillable = [
        'tenant_id',
        'connected_by',
        'status',
        'ml_user_id',
        'nickname',
        'site_id',
        'currency_id',
        'user_products',
        'token_expires_at',
        'connected_at',
        'sync_enabled',
        'listing_type_id',
        'condition',
        'default_quantity',
        'last_full_sync_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'ml_user_id' => 'integer',
            'user_products' => 'boolean',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'sync_enabled' => 'boolean',
            'default_quantity' => 'integer',
            'last_full_sync_at' => 'datetime',
        ];
    }

    public static function forTenant(int $tenantId): ?self
    {
        return self::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /** Conectada y con la sincronización automática encendida. */
    public function isSyncing(): bool
    {
        return $this->isConnected() && $this->sync_enabled;
    }

    public function setTokens(string $accessToken, string $refreshToken, int $expiresIn): void
    {
        $this->access_token = Crypt::encryptString($accessToken);
        $this->refresh_token = Crypt::encryptString($refreshToken);
        $this->token_expires_at = now()->addSeconds($expiresIn);
    }

    public function clearTokens(): void
    {
        $this->access_token = null;
        $this->refresh_token = null;
        $this->token_expires_at = null;
    }

    public function getDecryptedAccessToken(): ?string
    {
        return $this->decrypt($this->access_token);
    }

    public function getDecryptedRefreshToken(): ?string
    {
        return $this->decrypt($this->refresh_token);
    }

    private function decrypt(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            Log::error('Error decrypting Mercado Libre token for tenant '.$this->tenant_id, [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
