<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvoiceRecurrence extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'next_occurrence_on' => 'date:Y-m-d',
            'interval_count' => 'integer',
            'payment_term_days' => 'integer',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function contact(): BelongsTo { return $this->belongsTo(Contact::class); }
    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }
    public function latestInvoice(): HasOne { return $this->hasOne(Invoice::class)->latestOfMany(); }
}
