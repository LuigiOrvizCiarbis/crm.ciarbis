<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Configuración del canal Facebook Messenger (una página de Facebook).
 *
 * El modelo se llama Messenger pero el ChannelType es FACEBOOK y la relación en
 * Channel se llama facebookConfig(): `facebook` ya es el namespace del proveedor
 * compartido por los tres canales (config('services.facebook.*'),
 * FacebookDataDeletionController), así que el modelo usa el nombre del producto
 * para desambiguar, y la relación conserva el nombre que el front ya espera en
 * el JSON (`facebook_config`).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $page_id
 * @property string|null $page_name
 * @property bool $ai_autoreply_default
 */
class MessengerConfig extends Model
{
    protected $table = 'messenger_configs';

    protected $fillable = [
        'tenant_id',
        'page_id',
        'page_name',
        'page_access_token',
        'ai_autoreply_default',
    ];

    protected $casts = [
        'ai_autoreply_default' => 'boolean',
    ];

    protected $hidden = [
        'page_access_token',
    ];

    /**
     * Relación con Channels (una config puede pertenecer a varios canales).
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'messenger_config_id');
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
            Log::error('Error decrypting Messenger token for config '.$this->id, [
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
