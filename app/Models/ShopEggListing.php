<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopEggListing extends Model
{
    protected $guarded = [];

    protected $casts = ['listing_price' => 'integer', 'expires_at' => 'datetime', 'sold_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function shop() { return $this->belongsTo(PlayerShop::class); }
    public function seller() { return $this->belongsTo(Character::class, 'seller_character_id'); }
    public function buyer() { return $this->belongsTo(Character::class, 'buyer_character_id'); }
    public function egg() { return $this->belongsTo(PlayerValmonEgg::class, 'player_valmon_egg_id'); }
    public function master() { return $this->belongsTo(ValmonMaster::class, 'valmon_master_id'); }
    public function scopeActive($query) { return $query->where('status', 'active')->where('expires_at', '>', now()); }
}
