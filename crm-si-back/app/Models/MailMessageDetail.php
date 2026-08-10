<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailMessageDetail extends Model
{
    protected $fillable = [
        'message_id',
        'subject',
        'body_text',
        'body_html',
        'from',
        'to',
        'cc',
        'bcc',
        'reply_to',
        'in_reply_to',
        'references',
        'has_remote_images',
    ];

    protected $casts = [
        'from' => 'array',
        'to' => 'array',
        'cc' => 'array',
        'bcc' => 'array',
        'reply_to' => 'array',
        'in_reply_to' => 'array',
        'references' => 'array',
        'has_remote_images' => 'boolean',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
