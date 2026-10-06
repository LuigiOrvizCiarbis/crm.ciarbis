<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'issued_on' => 'date:Y-m-d',
            'due_on' => 'date:Y-m-d',
            'scheduled_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'next_reminder_at' => 'immutable_datetime',
            'issuer_snapshot' => 'array',
            'contact_snapshot' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(InvoiceRecurrence::class, 'invoice_recurrence_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(InvoiceEvent::class)->latest();
    }

    public function paidCents(): int
    {
        return (int) $this->payments()->whereNull('reversed_at')->sum('amount_cents');
    }

    public function balanceCents(): int
    {
        return max(0, (int) $this->amount_cents - $this->paidCents());
    }

    public function paymentState(?int $paidCents = null): string
    {
        $paid = $paidCents ?? $this->paidCents();

        return $paid === 0 ? 'pending' : ($paid >= $this->amount_cents ? 'paid' : 'partial');
    }

    public function collectionStatus(?int $paidCents = null, ?string $today = null): ?string
    {
        if ($this->status === 'void') {
            return null;
        }

        $paid = $paidCents ?? $this->paidCents();
        if ($paid >= $this->amount_cents) {
            return 'paid';
        }

        $overdue = $this->due_on !== null
            && $this->due_on->format('Y-m-d') < ($today ?? now()->toDateString());

        if ($paid > 0) {
            return $overdue ? 'partial_overdue' : 'partial';
        }

        return $overdue ? 'overdue' : 'pending';
    }
}
