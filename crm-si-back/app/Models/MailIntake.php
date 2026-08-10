<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailIntake extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'channel_id', 'mail_config_id', 'accepted_message_id', 'external_id', 'status',
        'classification_reason', 'from_email', 'from_name', 'mail_message_id', 'subject', 'body_text', 'body_html',
        'to', 'cc', 'bcc', 'reply_to', 'in_reply_to', 'references', 'attachments',
        'has_remote_images', 'received_at', 'decided_at', 'decided_by', 'expires_at',
    ];

    protected $casts = [
        'to' => 'array', 'cc' => 'array', 'bcc' => 'array', 'reply_to' => 'array',
        'in_reply_to' => 'array', 'references' => 'array', 'attachments' => 'array',
        'has_remote_images' => 'boolean', 'received_at' => 'datetime', 'decided_at' => 'datetime',
        'expires_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function acceptedMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'accepted_message_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
