<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Credencial de Mercado Pago (Checkout Pro) por tenant. El access token se
 * guarda encriptado y nunca se expone.
 *
 * @property int $tenant_id
 * @property ?string $statement_descriptor
 * @property string $webhook_token
 * @property ?Carbon $last_verified_at
 */
class MercadoPagoConfig extends Model
{
    use BelongsToTenant;

    protected $table = 'mercadopago_configs';

    // access_token NO es fillable: solo se escribe encriptado.
    protected $fillable = [
        'tenant_id',
        'statement_descriptor',
        'webhook_token',
        'last_verified_at',
    ];

    protected $hidden = [
        'access_token',
        'webhook_token',
    ];

    protected function casts(): array
    {
        return [
            'last_verified_at' => 'datetime',
        ];
    }

    public function setEncryptedAccessToken(string $value): void
    {
        $this->access_token = Crypt::encryptString($value);
    }

    public function getDecryptedAccessToken(): ?string
    {
        if (! $this->access_token) {
            return null;
        }

        try {
            return Crypt::decryptString($this->access_token);
        } catch (\Exception $e) {
            Log::error('Error decrypting Mercado Pago credential for tenant '.$this->tenant_id, [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function hasCredentials(): bool
    {
        return ! empty($this->access_token);
    }

    public function webhookUrl(): string
    {
        return config('app.url').'/api/mercadopago-webhook/'.$this->webhook_token;
    }
}
