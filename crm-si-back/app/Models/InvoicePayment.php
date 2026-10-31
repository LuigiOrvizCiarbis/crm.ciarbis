<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_on' => 'date:Y-m-d', 'reversed_at' => 'immutable_datetime'];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
