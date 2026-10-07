<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppWebhookReceipt extends Model
{
    protected $table = 'whatsapp_webhook_receipts';

    protected $fillable = [
        'dedupe_key', 'queue_name', 'payload', 'status', 'change_index', 'attempts',
        'last_error', 'processing_at', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processing_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
