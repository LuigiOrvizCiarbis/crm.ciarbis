<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImport extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'requested_by', 'original_filename', 'file_path', 'sheet_name',
        'status', 'mode', 'match_field', 'preserve_empty', 'mapping', 'proposed_fields',
        'preview', 'result', 'created_fields', 'error', 'queued_at', 'started_at',
        'finished_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'preserve_empty' => 'boolean',
            'mapping' => 'array',
            'proposed_fields' => 'array',
            'preview' => 'array',
            'result' => 'array',
            'created_fields' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function cancel(): bool
    {
        return static::withoutGlobalScopes()->whereKey($this->id)->where('status', 'queued')->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'updated_at' => now(),
        ]) > 0;
    }
}
