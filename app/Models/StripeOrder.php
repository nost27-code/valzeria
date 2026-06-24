<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeOrder extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fulfilled_at' => 'datetime',
        'kiseki_amount' => 'integer',
        'price_jpy' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
