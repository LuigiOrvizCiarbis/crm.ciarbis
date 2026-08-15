<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class WhatsAppConfig extends Model
{
    /**
     * Estados de la sincronización de contactos de coexistencia (SMB).
     *
     * Meta permite disparar el sync una sola vez por onboarding, con una ventana
     * de 24h. `syncing` significa que Meta aceptó el pedido pero todavía no
     * llegó ningún webhook; solo `completed` prueba que los contactos llegaron.
     */
    public const SYNC_PENDING = 'pending';

    public const SYNC_SYNCING = 'syncing';

    public const SYNC_COMPLETED = 'completed';

    public const SYNC_FAILED = 'failed';

    /** El número no viene de la WhatsApp Business App: no hay contactos que traer. */
    public const SYNC_NOT_APPLICABLE = 'not_applicable';

    /** Ventana que da Meta para completar el sync antes de exigir re-onboarding. */
    public const SYNC_WINDOW_HOURS = 24;

    protected $table = 'whatsapp_configs';

    protected $fillable = [
        'phone_number_id',
        'phone_number',
        'display_phone_number',
        'waba_id',
        'business_id',
        'quality_rating',
        'webhook_url',
        'verify_token',
        'bussines_token',
        'registration_pin',
        'ai_autoreply_default',
        'ai_system_prompt',
        'contact_sync_status',
        'contact_sync_requested_at',
        'contact_sync_first_webhook_at',
        'contact_sync_last_webhook_at',
        'contact_sync_contacts_count',
        'contact_sync_request_id',
        'contact_sync_error',
        'contact_sync_retryable',
        'contact_history_sync_status',
        'contact_history_sync_requested_at',
        'contact_history_sync_request_id',
        'contact_history_sync_error',
        'contact_history_sync_messages_count',
        'contact_sync_error_code',
        'meta_app_usage_pct',
        'meta_app_usage_at',
    ];

    protected $casts = [
        'ai_autoreply_default' => 'boolean',
        'contact_sync_requested_at' => 'datetime',
        'contact_sync_first_webhook_at' => 'datetime',
        'contact_sync_last_webhook_at' => 'datetime',
        'contact_sync_contacts_count' => 'integer',
        'contact_sync_retryable' => 'boolean',
        'contact_history_sync_requested_at' => 'datetime',
        'contact_history_sync_messages_count' => 'integer',
        'meta_app_usage_pct' => 'integer',
        'meta_app_usage_at' => 'datetime',
    ];

    /**
     * Umbral desde el que se considera la cuota de Meta agotada (doc oficial:
     * ≥90% es "critical"). No se dispara smb_app_data si la última lectura del
     * header X-App-Usage está en o por encima de esto.
     */
    public const META_USAGE_CRITICAL_PCT = 90;

    /**
     * Ventana de vigencia de la última lectura de cuota. Pasado esto se
     * considera obsoleta y no bloquea el disparo (evita quedar bloqueado por
     * una lectura vieja si la cuota ya se liberó).
     */
    public const META_USAGE_STALE_MINUTES = 15;

    protected $hidden = [
        'bussines_token',
        'registration_pin',
    ];

    /**
     * Relación con Channels (una config puede pertenecer a varios canales)
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class, 'whatsapp_config_id');
    }

    /**
     * Relación con WhatsApp Templates
     */
    public function templates(): HasMany
    {
        return $this->hasMany(WhatsAppTemplate::class);
    }

    public function getDecryptedToken(): ?string
    {
        if (! $this->bussines_token) {
            return null;
        }

        try {
            return Crypt::decryptString($this->bussines_token);
        } catch (\Exception $e) {
            Log::error('Error decrypting token for channel '.$this->id, [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Establecer el access token encriptado
     */
    public function setEncryptedToken(string $token): void
    {
        $this->bussines_token = Crypt::encryptString($token);
        $this->save();
    }

    public function getDecryptedRegistrationPin(): ?string
    {
        if (! $this->registration_pin) {
            return null;
        }

        try {
            return Crypt::decryptString($this->registration_pin);
        } catch (\Exception $e) {
            Log::error('Error decrypting registration pin for config '.$this->id, [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Establecer el PIN de registro (two-step verification) encriptado
     */
    public function setEncryptedRegistrationPin(string $pin): void
    {
        $this->registration_pin = Crypt::encryptString($pin);
        $this->save();
    }

    /**
     * Momento en que expira la ventana de 24h que da Meta para sincronizar.
     * Pasada esa ventana el sync ya no se puede reintentar: hay que offboardear
     * al cliente y rehacer el Embedded Signup.
     */
    public function contactSyncWindowExpiresAt(): ?Carbon
    {
        return $this->contact_sync_requested_at?->addHours(self::SYNC_WINDOW_HOURS);
    }

    /**
     * ¿Todavía estamos dentro de la ventana en que Meta acepta reintentos?
     * Si nunca disparamos el sync, la ventana no arrancó y sigue abierta.
     */
    public function isWithinContactSyncWindow(): bool
    {
        $expiresAt = $this->contactSyncWindowExpiresAt();

        return $expiresAt === null || $expiresAt->isFuture();
    }

    /**
     * El sync quedó colgado: Meta aceptó el pedido pero no se importó ni un
     * contacto. Es el caso que antes se perdía en silencio.
     *
     * Se mide por contactos importados y no por "llegó un webhook", porque Meta
     * emite smb_app_state_sync también ante cambios manuales del cliente en su
     * agenda: un `remove` suelto llegaría sin que la importación haya ocurrido.
     */
    public function hasStalledContactSync(): bool
    {
        return $this->contact_sync_status === self::SYNC_SYNCING
            && $this->contact_sync_contacts_count === 0;
    }

    /**
     * ¿La última lectura conocida de la cuota de Meta (header X-App-Usage del
     * business token del cliente) está en nivel crítico y sigue vigente?
     *
     * Se usa como guard antes de llamar a smb_app_data: esa cuota es distinta
     * de la cuota de la app (visible en el dashboard de Meta) y sólo se puede
     * ver leyendo este header en respuestas anteriores, nunca por sondeo.
     */
    public function hasCriticalMetaUsage(): bool
    {
        if ($this->meta_app_usage_pct === null || $this->meta_app_usage_at === null) {
            return false;
        }

        if ($this->meta_app_usage_pct < self::META_USAGE_CRITICAL_PCT) {
            return false;
        }

        return $this->meta_app_usage_at->isAfter(now()->subMinutes(self::META_USAGE_STALE_MINUTES));
    }
}
