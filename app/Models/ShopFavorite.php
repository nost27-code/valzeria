<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopFavorite extends Model
{
    protected $guarded = [];
    public function shop() { return $this->belongsTo(PlayerShop::class); }
    public function character() { return $this->belongsTo(Character::class); }
}
