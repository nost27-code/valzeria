<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TowerRun extends Model
{
    protected $guarded = [];

    protected $casts = [
        'current_floor' => 'integer',
        'reached_floor' => 'integer',
        'cleared_floor' => 'integer',
        'failed_floor' => 'integer',
        'tower_max_hp' => 'integer',
        'tower_current_hp' => 'integer',
        'tower_max_mp' => 'integer',
        'tower_current_mp' => 'integer',
        'total_wins' => 'integer',
        'total_losses' => 'integer',
        'merchant_encounter_count' => 'integer',
        'last_merchant_floor' => 'integer',
        'gold_spent' => 'integer',
        'stamina_spent' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function events()
    {
        return $this->hasMany(TowerRunEvent::class);
    }

    public function merchantPurchases()
    {
        return $this->hasMany(TowerMerchantPurchase::class);
    }
}
