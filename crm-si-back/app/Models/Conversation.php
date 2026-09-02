<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranch;
use App\Models\Concerns\HasTags;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $channel_id
 * @property int|null $contact_id
 * @property string $kind
 * @property int|null $assigned_to
 * @property string $status
 * @property bool $manual_unread
 * @property Carbon|null $last_message_at
 * @property string|null $last_message_content
 * @property int|null $pipeline_stage_id
 * @property bool $ai_autoreply_enabled
 * @property string|null $contact_language
 * @property Carbon|null $archived_at
 */
class Conversation extends Model
{
    use BelongsToTenant;
    use HasBranch;
    use HasTags;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'channel_id',
        'contact_id',
        'kind',
        'assigned_to',
        'status',
        'manual_unread',
        'last_message_at',
        'last_message_content',
        'pipeline_stage_id',
        'ai_autoreply_enabled',
        'contact_language',
        'archived_at',
    ];

    protected $appends = ['archived'];

    protected $casts = [
        'manual_unread' => 'boolean',
        'ai_autoreply_enabled' => 'boolean',
        'last_message_at' => 'datetime',
        'archived_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function archived(): Attribute
    {
        return Attribute::make(
            get: fn () => ! is_null($this->archived_at),
        );
    }

    /**
     * Relación con tenant
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Relación con channel
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Relación con contact. Null en conversaciones de grupo (kind='group').
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * FK inversa: el grupo de WhatsApp que generó esta conversación, si es
     * de tipo 'group'. Se guarda la relación del lado de WhatsAppGroup
     * (conversation_id unique) para no tocar el $fillable de este modelo.
     */
    public function whatsappGroup(): HasOne
    {
        return $this->hasOne(WhatsAppGroup::class);
    }

    public function isGroup(): bool
    {
        return $this->kind === 'group';
    }

    /**
     * Relación con usuario asignado (responsable principal)
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Relación many-to-many con usuarios asignados/derivados
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_user')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    /**
     * Relación con messages
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->withTrashed();
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Scope para conversaciones abiertas
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope para conversaciones cerradas
     */
    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    /**
     * Scope para conversaciones pendientes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope para conversaciones asignadas a un usuario
     */
    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Scope para conversaciones sin asignar
     */
    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_to');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('conversations.view_any')) {
            return $query;
        }

        $accessibleChannelIds = $user->accessibleChannelIds();

        return $query->where(function (Builder $q) use ($user, $accessibleChannelIds) {
            $q->where('assigned_to', $user->id)
                ->orWhereHas('users', fn (Builder $sub) => $sub->where('users.id', $user->id));

            if (! empty($accessibleChannelIds)) {
                $q->orWhereIn('channel_id', $accessibleChannelIds);
            }
        });
    }

    /**
     * Obtener el último mensaje
     */
    public function lastMessage(): ?Message
    {
        return $this->messages()
            ->withoutTrashed()
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    public function syncLastMessageSummary(): void
    {
        $lastMessage = $this->lastMessage();

        $this->updateQuietly([
            'last_message_at' => $lastMessage?->created_at,
            'last_message_content' => $lastMessage?->conversationPreviewContent(),
        ]);
    }

    /**
     * Asignar a un usuario
     */
    public function assignTo(int $userId): void
    {
        $this->update(['assigned_to' => $userId]);
    }

    /**
     * Desasignar usuario
     */
    public function unassign(): void
    {
        $this->update(['assigned_to' => null]);
    }

    /**
     * Cerrar conversación
     */
    public function close(): void
    {
        $this->update(['status' => 'closed']);
    }

    /**
     * Abrir conversación
     */
    public function open(): void
    {
        $this->update(['status' => 'open']);
    }

    /**
     * Marcar como pendiente
     */
    public function markAsPending(): void
    {
        $this->update(['status' => 'pending']);
    }

    /**
     * Actualizar timestamp del último mensaje
     */
    public function updateLastMessageAt(): void
    {
        $this->update(['last_message_at' => now()]);
    }

    /**
     * Verificar si está abierta
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Verificar si está cerrada
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Verificar si está asignada
     */
    public function isAssigned(): bool
    {
        return ! is_null($this->assigned_to);
    }

    public function pipelineStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function archive(): void
    {
        $this->update(['archived_at' => now()]);
    }

    public function unarchive(): void
    {
        $this->update(['archived_at' => null]);
    }
}
