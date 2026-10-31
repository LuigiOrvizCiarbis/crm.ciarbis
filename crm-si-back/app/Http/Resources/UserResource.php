<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialización única del usuario para login, register y GET /api/user.
 *
 * IMPORTANTE: AuthGuard.tsx (front) lee `tenant.plan.key` y
 * `tenant.trial_ends_at` con este anidamiento exacto para decidir si el
 * trial venció (lib/trial.ts). No aplanar ni renombrar esas claves sin
 * actualizar ambos lados — ver tests/Feature/Api/UserSessionContractTest.php.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'tenant_id' => $this->tenant_id,
            'branch_id' => $this->branch_id,
            'phone' => $this->phone,
            'job_title' => $this->job_title,
            'avatar_url' => $this->avatarUrl(),
            'preferences' => $this->preferencesWithDefaults(),
            'tenant' => $this->whenLoaded('tenant', fn () => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'owner_role_id' => $this->tenant->owner_role_id,
                'plan_id' => $this->tenant->plan_id,
                'trial_ends_at' => $this->tenant->trial_ends_at,
                'navigation_labels' => $this->tenant->navigation_labels,
                'plan' => $this->whenLoaded('tenant', fn () => $this->tenant->relationLoaded('plan') && $this->tenant->plan
                    ? [
                        'id' => $this->tenant->plan->id,
                        'key' => $this->tenant->plan->key,
                        'name' => $this->tenant->plan->name,
                    ]
                    : null),
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
