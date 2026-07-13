<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentMarketTransaction extends Model
{
    protected $guarded = [];

    protected $casts = [
        'item_snapshot' => 'array', 'sale_price' => 'integer', 'fee_rate_bps' => 'integer',
        'fee_amount' => 'integer', 'seller_proceeds' => 'integer', 'sold_at' => 'datetime',
    ];

    public function listing() { return $this->belongsTo(EquipmentMarketListing::class, 'listing_id'); }
    public function seller() { return $this->belongsTo(Character::class, 'seller_character_id'); }
    public function buyer() { return $this->belongsTo(Character::class, 'buyer_character_id'); }
}
