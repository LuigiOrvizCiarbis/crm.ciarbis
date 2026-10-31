<?php

namespace App\Models;

use App\Enums\AiProvider;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Config de IA por tenant (BYOK): proveedor, API key encriptada, modelo,
 * enabled y system prompt. La lee el auto-responder de conversaciones.
 *
 * @property AiProvider $provider
 * @property bool $enabled
 * @property ?string $model
 * @property ?string $system_prompt
 */
class AiConfig extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'provider',
        'api_key',
        'model',
        'enabled',
        'human_handoff_enabled',
        'system_prompt',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'provider' => AiProvider::class,
            'enabled' => 'boolean',
            'human_handoff_enabled' => 'boolean',
        ];
    }

    /**
     * Devuelve la API key desencriptada, o null si no hay o falla el descifrado.
     * No lanza: loguea y devuelve null (mismo criterio que WhatsAppConfig).
     */
    public function getDecryptedApiKey(): ?string
    {
        if (! $this->api_key) {
            return null;
        }

        try {
            return Crypt::decryptString($this->api_key);
        } catch (\Exception $e) {
            Log::error('Error decrypting AI api_key for tenant '.$this->tenant_id, [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Encripta y persiste la API key.
     */
    public function setEncryptedApiKey(string $apiKey): void
    {
        $this->api_key = Crypt::encryptString($apiKey);
        $this->save();
    }
}
