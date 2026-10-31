<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantMembership;

/** Request-scoped tenant context. Never persist the selected workspace on User. */
class CurrentWorkspace
{
    private ?TenantMembership $membership = null;

    public function set(TenantMembership $membership): void
    {
        $this->membership = $membership;
    }

    public function membership(): ?TenantMembership
    {
        return $this->membership;
    }

    public function id(): ?int
    {
        return $this->membership?->tenant_id;
    }

    public function tenant(): ?Tenant
    {
        return $this->membership?->tenant;
    }
}
