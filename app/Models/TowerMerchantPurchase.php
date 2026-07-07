<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TowerMerchantPurchase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'floor' => 'integer',
        'price' => 'integer',
        'effect_value' => 'integer',
        'activated_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function towerRun()
    {
        return $this->belongsTo(TowerRun::class);
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
