<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'plan_id',
        'trial_ends_at',
        'timezone',
        'navigation_labels',
        'owner_role_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'navigation_labels' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant) {
            if ($tenant->plan_id === null) {
                $tenant->plan_id = Plan::where('key', 'free')->value('id');
            }
        });

        static::created(function (Tenant $tenant) {
            $now = now();
            $stages = [
                ['name' => 'Capturados', 'sort_order' => 1, 'is_default' => true],
                ['name' => 'Calificados', 'sort_order' => 2, 'is_default' => false],
                ['name' => 'Negociación', 'sort_order' => 3, 'is_default' => false],
                ['name' => 'Cierre', 'sort_order' => 4, 'is_default' => false],
                ['name' => 'Ganado', 'sort_order' => 5, 'is_default' => false],
                ['name' => 'Perdido', 'sort_order' => 6, 'is_default' => false],
            ];

            PipelineStage::insert(array_map(fn ($stage) => [
                ...$stage,
                'tenant_id' => $tenant->id,
                'created_at' => $now,
                'updated_at' => $now,
            ], $stages));
        });
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function ownerRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'owner_role_id');
    }

    /**
     * Relación con channels
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Relación con contacts
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Relación con conversations
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    /**
     * Relación con messages
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function automationRules(): HasMany
    {
        return $this->hasMany(AutomationRule::class);
    }

    /**
     * Scope para plan específico (por key)
     */
    public function scopePlan($query, string $key)
    {
        return $query->whereHas('plan', fn ($q) => $q->where('key', $key));
    }

    /**
     * Key del plan actual. Sin plan asignado se trata como 'free'.
     */
    public function planKey(): string
    {
        return $this->plan?->key ?? 'free';
    }

    /**
     * Verificar si es plan free
     */
    public function isFreePlan(): bool
    {
        return $this->planKey() === 'free';
    }

    /**
     * Verificar si es plan pro
     */
    public function isProPlan(): bool
    {
        return $this->planKey() === 'pro';
    }

    /**
     * Verificar si es plan enterprise
     */
    public function isEnterprisePlan(): bool
    {
        return $this->planKey() === 'enterprise';
    }

    /**
     * Trial activo (plan free, con fecha de vencimiento futura).
     */
    public function onTrial(): bool
    {
        return $this->isFreePlan() && $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * Trial vencido (plan free, con fecha de vencimiento pasada).
     */
    public function trialExpired(): bool
    {
        return $this->isFreePlan() && $this->trial_ends_at !== null && $this->trial_ends_at->isPast();
    }

    /**
     * Días restantes de trial, o null si no está en trial.
     */
    public function trialDaysLeft(): ?int
    {
        if (! $this->onTrial()) {
            return null;
        }

        return max(0, (int) ceil(now()->diffInHours($this->trial_ends_at, false) / 24));
    }
}
