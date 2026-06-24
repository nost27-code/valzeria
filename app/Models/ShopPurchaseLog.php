<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopPurchaseLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'total_kiseki_cost' => 'integer',
        'free_kiseki_spent' => 'integer',
        'paid_kiseki_spent' => 'integer',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
