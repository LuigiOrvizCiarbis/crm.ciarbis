<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceAuditEvent extends Model
{
    protected $fillable = ['tenant_id', 'actor_user_id', 'subject_user_id', 'event', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
