<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InvoiceEvent extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
    protected function casts(): array { return ['details' => 'array']; }
}
