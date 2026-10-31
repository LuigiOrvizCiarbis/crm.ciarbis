<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InvoiceSetting extends Model
{
    use BelongsToTenant;
    protected $guarded = ['id'];
    public function whatsappTemplate() { return $this->belongsTo(WhatsAppTemplate::class, 'whatsapp_template_id'); }
    protected function casts(): array { return ['enabled' => 'boolean', 'reminder_days' => 'array']; }
}
