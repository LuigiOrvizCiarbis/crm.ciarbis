<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceTemplateProvisioning extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    public function invoiceTemplate()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'invoice_template_id');
    }

    public function reminderTemplate()
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'reminder_template_id');
    }

    public function whatsappConfig(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConfig::class);
    }

    public function refreshState(): void
    {
        $this->loadMissing(['invoiceTemplate', 'reminderTemplate']);
        $templates = [$this->invoiceTemplate, $this->reminderTemplate];
        if (collect($templates)->contains(fn ($template) => $template?->status?->value === 'REJECTED')) {
            $state = 'rejected';
        } elseif ($this->invoiceTemplate?->isApproved() && $this->reminderTemplate?->isApproved()) {
            $state = 'ready';
        } elseif ($this->invoice_error || $this->reminder_error) {
            $state = 'partial';
        } elseif (collect($templates)->contains(fn ($template) => $template !== null)) {
            $state = 'pending_review';
        } else {
            $state = 'queued';
        }
        $this->forceFill(['state' => $state])->save();
    }

    public function toStatusArray(): array
    {
        $this->loadMissing(['invoiceTemplate', 'reminderTemplate']);
        $serialize = fn ($template) => $template ? [
            'id' => $template->id,
            'name' => $template->name,
            'status' => $template->status?->value ?? (string) $template->status,
            'rejected_reason' => $template->rejected_reason,
        ] : null;

        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'state' => $this->state,
            'invoice' => $serialize($this->invoiceTemplate),
            'reminder' => $serialize($this->reminderTemplate),
            'invoice_error' => $this->invoice_error,
            'reminder_error' => $this->reminder_error,
        ];
    }
}
