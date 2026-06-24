<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketTransaction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'integer',
        'total_price' => 'integer',
        'sale_fee' => 'integer',
        'seller_received' => 'integer',
        'created_at' => 'datetime',
    ];

    public function listing()
    {
        return $this->belongsTo(MarketListing::class, 'listing_id');
    }

    public function seller()
    {
        return $this->belongsTo(Character::class, 'seller_character_id');
    }

    public function buyer()
    {
        return $this->belongsTo(Character::class, 'buyer_character_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
