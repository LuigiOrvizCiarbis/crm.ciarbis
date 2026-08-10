<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailChannelRule extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'channel_id', 'type', 'value_type', 'value'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
