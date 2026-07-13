<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentMarketListing extends Model
{
    protected $guarded = [];

    protected $casts = [
        'item_snapshot' => 'array', 'appraisal_price' => 'integer', 'minimum_price' => 'integer',
        'maximum_price' => 'integer', 'listing_price' => 'integer', 'fee_rate_bps' => 'integer',
        'fee_amount' => 'integer', 'seller_proceeds' => 'integer', 'enhance_level' => 'integer',
        'engraving_level' => 'integer', 'slayer_level' => 'integer', 'expires_at' => 'datetime',
        'sold_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function seller() { return $this->belongsTo(Character::class, 'seller_character_id'); }
    public function buyer() { return $this->belongsTo(Character::class, 'buyer_character_id'); }
    public function characterItem() { return $this->belongsTo(CharacterItem::class); }
    public function transaction() { return $this->hasOne(EquipmentMarketTransaction::class, 'listing_id'); }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->where('expires_at', '>', now());
    }
}
