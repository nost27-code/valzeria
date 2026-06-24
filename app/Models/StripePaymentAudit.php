<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripePaymentAudit extends Model
{
    protected $guarded = [];

    protected $casts = [
        'price_jpy' => 'integer',
        'kiseki_amount' => 'integer',
        'webhook_received_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'payload' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(StripeOrder::class, 'stripe_order_id');
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
