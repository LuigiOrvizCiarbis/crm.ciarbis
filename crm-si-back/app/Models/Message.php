<?php

namespace App\Models;

use App\Enums\BroadcastRecipientStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\SenderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'sender_type',
        'sender_id',
        'content',
        'message_type',
        'contacts',
        'media_url',
        'media_mime_type',
        'media_filename',
        'direction',
        'external_id',
        'mail_message_id',
        'mail_parent_message_id',
        'delivered_at',
        'read_at',
        'played_at',
        'failed_at',
        'error_message',
        'edited_at',
        'original_content',
    ];

    protected $casts = [
        'sender_type' => SenderType::class,
        'direction' => MessageDirection::class,
        'message_type' => MessageType::class,
        'contacts' => 'array',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'played_at' => 'datetime',
        'failed_at' => 'datetime',
        'edited_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = ['media_full_url'];

    public function getMediaFullUrlAttribute(): ?string
    {
        if (! $this->media_url) {
            return null;
        }

        if (str_starts_with($this->media_url, 'http')) {
            return $this->media_url;
        }

        return rtrim(config('app.url'), '/').$this->media_url;
    }

    public function sender(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Relación con tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Relación con conversation
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(MessageTranslation::class);
    }

    public function mailDetails(): HasOne
    {
        return $this->hasOne(MailMessageDetail::class);
    }

    public function mailAttachments(): HasMany
    {
        return $this->hasMany(self::class, 'mail_parent_message_id')->orderBy('id');
    }

    public function mailParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'mail_parent_message_id');
    }

    public function broadcastRecipient(): HasOne
    {
        return $this->hasOne(BroadcastRecipient::class);
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(MessageInteraction::class, 'target_message_id');
    }

    /**
     * Scope para mensajes entrantes
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', MessageDirection::INBOUND);
    }

    /**
     * Scope para mensajes salientes
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', MessageDirection::OUTBOUND);
    }

    /**
     * Scope para mensajes entregados
     */
    public function scopeDelivered($query)
    {
        return $query->whereNotNull('delivered_at');
    }

    /**
     * Scope para mensajes leídos
     */
    public function scopeRead($query)
    {
        return $query->whereNotNull('read_at');
    }

    /**
     * Marcar mensaje como entregado
     */
    public function markAsDelivered(): void
    {
        $this->update(['delivered_at' => now()]);
    }

    /**
     * Marcar mensaje como leído
     */
    public function markAsRead(): void
    {
        $this->update(['read_at' => now()]);
    }

    /**
     * Marcar un mensaje de voz como reproducido. Meta lo reporta solo la primera
     * vez, así que no tiene sentido volver a escribirlo si ya está seteado.
     */
    public function markAsPlayed(): void
    {
        $this->update(['played_at' => now()]);
    }

    /**
     * Marcar mensaje como fallido, guardando el error reportado por el canal.
     */
    public function markAsFailed(?string $error = null): void
    {
        $this->update([
            'failed_at' => now(),
            'error_message' => $error,
        ]);

        $recipient = $this->broadcastRecipient()->with('campaign')->first();
        if ($recipient) {
            $recipient->update([
                'status' => BroadcastRecipientStatus::Failed,
                'error' => $error,
            ]);
            $recipient->campaign->refreshDeliveryStatus();
        }
    }

    /**
     * Verificar si el mensaje falló al enviarse
     */
    public function isFailed(): bool
    {
        return ! is_null($this->failed_at);
    }

    /**
     * Verificar si el mensaje fue entregado
     */
    public function isDelivered(): bool
    {
        return ! is_null($this->delivered_at);
    }

    /**
     * Verificar si el mensaje fue leído
     */
    public function isRead(): bool
    {
        return ! is_null($this->read_at);
    }

    /**
     * Verificar si el mensaje de voz fue reproducido
     */
    public function isPlayed(): bool
    {
        return ! is_null($this->played_at);
    }

    public function isEdited(): bool
    {
        return ! is_null($this->edited_at);
    }

    public function conversationPreviewContent(): string
    {
        return match ($this->message_type) {
            MessageType::Image => '📷 '.($this->content ?: 'Imagen'),
            MessageType::Sticker => '🏷️ Sticker',
            MessageType::Video => '🎥 '.($this->content ?: 'Video'),
            MessageType::Audio => '🎵 Audio',
            MessageType::Document => '📄 '.($this->content ?: 'Documento'),
            MessageType::Contacts => '👤 '.($this->content ?: 'Contactos compartidos'),
            default => $this->content ?? '',
        };
    }
}
