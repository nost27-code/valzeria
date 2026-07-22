<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlayerShop extends Model
{
    protected $guarded = [];

    protected $casts = [
        'name_changed_at' => 'datetime',
        'last_stocked_at' => 'datetime',
    ];

    public function character() { return $this->belongsTo(Character::class); }
    public function materialListings() { return $this->hasMany(MarketListing::class, 'shop_id'); }
    public function equipmentListings() { return $this->hasMany(EquipmentMarketListing::class, 'shop_id'); }
    public function eggListings() { return $this->hasMany(ShopEggListing::class, 'shop_id'); }
    public function favorites() { return $this->hasMany(ShopFavorite::class, 'shop_id'); }
    public function isOpen(): bool { return $this->status === 'open'; }
}
