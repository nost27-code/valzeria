<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PassPurchaseLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price_amount' => 'integer',
        'purchased_at' => 'datetime',
        'previous_expires_at' => 'datetime',
        'new_expires_at' => 'datetime',
    ];
}
