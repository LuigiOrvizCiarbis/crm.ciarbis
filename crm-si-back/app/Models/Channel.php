<?php

namespace App\Models;

use App\Enums\ChannelType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $user_id
 * @property int|null $whatsapp_config_id
 * @property int|null $instagram_config_id
 * @property int|null $messenger_config_id
 * @property int|null $mail_config_id
 * @property ChannelType $type
 * @property string $name
 * @property string|null $external_id
 * @property string $status
 */
class Channel extends Model
{
    use BelongsToTenant;
    use HasBranch;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'user_id',
        'whatsapp_config_id',
        'instagram_config_id',
        'messenger_config_id',
        'mail_config_id',
        'type',
        'name',
        'external_id',
        'status',
    ];

    protected $casts = [
        'type' => ChannelType::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Relación con user (dueño principal)
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación many-to-many con usuarios asignados al canal
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'channel_user')
            ->withTimestamps();
    }

    /**
     * Relación con conversations
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Relación con WhatsAppConfig (muchos canales pueden compartir una config)
     */
    public function whatsappConfig(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConfig::class);
    }

    /**
     * Relación con InstagramConfig (muchos canales pueden compartir una config)
     */
    public function instagramConfig(): BelongsTo
    {
        return $this->belongsTo(InstagramConfig::class);
    }

    /**
     * Relación con MessengerConfig (canal FACEBOOK / Messenger).
     *
     * Se llama facebookConfig a propósito: el nombre de la relación define la
     * clave del JSON serializado (`facebook_config`), que es la que el front ya
     * consume en data/types.ts y lib/utils/channelHelpers.ts. La FK explícita es
     * necesaria porque el nombre de la relación no coincide con la columna.
     */
    public function facebookConfig(): BelongsTo
    {
        return $this->belongsTo(MessengerConfig::class, 'messenger_config_id');
    }

    /**
     * Relación con MailConfig (muchos canales pueden compartir una config)
     */
    public function mailConfig(): BelongsTo
    {
        return $this->belongsTo(MailConfig::class);
    }

    /**
     * Scope para canales activos
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope para canales desconectados
     */
    public function scopeDisconnected($query)
    {
        return $query->where('status', 'disconnected');
    }

    /**
     * Scope por tipo de canal
     */
    public function scopeOfType($query, ChannelType $type)
    {
        return $query->where('type', $type);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('channels.view_any')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('users', fn (Builder $sub) => $sub->where('users.id', $user->id));
        });
    }

    /**
     * Verificar si el canal está activo
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Verificar si el canal está desconectado
     */
    public function isDisconnected(): bool
    {
        return $this->status === 'disconnected';
    }

    /**
     * Activar el canal
     */
    public function activate(): void
    {
        $this->update(['status' => 'active']);
    }

    /**
     * Desconectar el canal
     */
    public function disconnect(): void
    {
        $this->update(['status' => 'disconnected']);
    }
}
