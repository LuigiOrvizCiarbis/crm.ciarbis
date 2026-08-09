<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class MailConfig extends Model
{
    protected $table = 'mail_configs';

    protected $fillable = [
        'tenant_id',
        'email_address',
        'from_name',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'password',
        'last_uid',
        'uidvalidity',
        'last_synced_at',
        'last_error',
        'ai_autoreply_default',
        'ai_system_prompt',
    ];

    protected $casts = [
        'ai_autoreply_default' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
    ];

    /**
     * Relación con Channels (una config puede pertenecer a varios canales)
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'mail_config_id');
    }

    public function getDecryptedPassword(): ?string
    {
        if (! $this->password) {
            return null;
        }

        try {
            return Crypt::decryptString($this->password);
        } catch (\Exception $e) {
            Log::error('Error decrypting password for mail config '.$this->id, [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Establecer la contraseña de la casilla encriptada
     */
    public function setEncryptedPassword(string $password): void
    {
        $this->password = Crypt::encryptString($password);
        $this->save();
    }
}
