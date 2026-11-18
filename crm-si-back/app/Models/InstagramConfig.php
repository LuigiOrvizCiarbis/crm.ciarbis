<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class InstagramConfig extends Model
{
    protected $table = 'instagram_configs';

    protected $fillable = [
        'tenant_id',
        'ig_user_id',
        'page_id',
        'webhook_object_id',
        'username',
        'page_access_token',
        'ai_autoreply_default',
        'ai_system_prompt',
        'meta_dataset_id',
    ];

    protected $casts = [
        'ai_autoreply_default' => 'boolean',
    ];

    protected $hidden = [
        'page_access_token',
    ];

    /**
     * Relación con Channels (una config puede pertenecer a varios canales)
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'instagram_config_id');
    }

    /**
     * Obtener el page access token descifrado.
     */
    public function getDecryptedToken(): ?string
    {
        if (! $this->page_access_token) {
            return null;
        }

        try {
            return Crypt::decryptString($this->page_access_token);
        } catch (\Exception $e) {
            Log::error('Error decrypting Instagram token for config '.$this->id, [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Establecer el page access token encriptado.
     */
    public function setEncryptedToken(string $token): void
    {
        $this->page_access_token = Crypt::encryptString($token);
        $this->save();
    }
}
