<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Notifications\CustomResetPassword;
use App\Notifications\CustomVerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property string $email
 */
class User extends Authenticatable implements MustVerifyEmail
{
    use BelongsToTenant, HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Force Spatie to resolve roles/permissions against a single guard regardless
     * of how the user was authenticated (Sanctum vs session). All roles in the
     * application are seeded under this guard.
     */
    protected string $guard_name = 'web';

    /**
     * Defaults aplicados por el accessor `preferences` sobre lo guardado en
     * DB, para que el front nunca reciba claves faltantes.
     */
    public const DEFAULT_PREFERENCES = [
        'locale' => 'es',
        'timezone' => 'America/Argentina/Buenos_Aires',
        'date_format' => 'dd/MM/yyyy',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'tenant_id',
        'branch_id',
        'email_verified_at',
        'avatar_path',
        'phone',
        'job_title',
        'preferences',
    ];

    protected ?bool $isTenantOwnerCache = null;

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /**
     * URL pública del avatar, o null si no tiene. Mismo patrón que
     * MediaAsset::publicUrl().
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path === null) {
            return null;
        }

        $base = config('app.url');

        return rtrim((string) $base, '/').'/storage/'.$this->avatar_path;
    }

    /**
     * Preferencias del usuario con los defaults ya mezclados.
     *
     * @return array<string, mixed>
     */
    public function preferencesWithDefaults(): array
    {
        return array_merge(self::DEFAULT_PREFERENCES, $this->preferences ?? []);
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new CustomVerifyEmail);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CustomResetPassword($token));
    }

    public function ownedChannels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_user')
            ->withTimestamps();
    }

    public function accessibleChannelIds(): array
    {
        $ownedIds = $this->ownedChannels()->pluck('id')->toArray();
        $assignedIds = $this->channels()->pluck('channels.id')->toArray();

        return array_unique(array_merge($ownedIds, $assignedIds));
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_to');
    }

    public function assignedOpportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'assigned_to');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function googleCalendarConnection(): HasOne
    {
        return $this->hasOne(GoogleCalendarConnection::class);
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_user')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function messages(): MorphMany
    {
        return $this->morphMany(Message::class, 'sender');
    }

    public function isTenantOwner(): bool
    {
        if ($this->isTenantOwnerCache !== null) {
            return $this->isTenantOwnerCache;
        }

        $ownerRoleId = $this->tenant?->owner_role_id;

        if ($ownerRoleId === null) {
            return $this->isTenantOwnerCache = false;
        }

        return $this->isTenantOwnerCache = $this->roles()
            ->where('roles.id', $ownerRoleId)
            ->where('roles.tenant_id', $this->tenant_id)
            ->exists();
    }

    public function forgetTenantOwnerCache(): void
    {
        $this->isTenantOwnerCache = null;
    }
}
