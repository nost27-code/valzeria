<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipmentMarketListing extends Model
{
    protected $guarded = [];

    protected $casts = [
        'item_snapshot' => 'array', 'body_appraisal_price' => 'integer', 'trait_appraisal_price' => 'integer',
        'appraisal_price' => 'integer', 'minimum_price' => 'integer', 'maximum_price' => 'integer',
        'listing_price' => 'integer', 'appraisal_version' => 'integer', 'fee_rate_bps' => 'integer',
        'fee_amount' => 'integer', 'seller_proceeds' => 'integer', 'enhance_level' => 'integer',
        'engraving_level' => 'integer', 'slayer_level' => 'integer', 'expires_at' => 'datetime',
        'sold_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function seller() { return $this->belongsTo(Character::class, 'seller_character_id'); }
    public function buyer() { return $this->belongsTo(Character::class, 'buyer_character_id'); }
    public function characterItem() { return $this->belongsTo(CharacterItem::class); }
    public function transaction() { return $this->hasOne(EquipmentMarketTransaction::class, 'listing_id'); }
    public function shop() { return $this->belongsTo(PlayerShop::class, 'shop_id'); }

    public function scopeActive($query)
    {
        $query->where('status', 'active')->where('expires_at', '>', now());

        if (config('features.player_shops_enabled', false)) {
            $query->whereHas('shop', fn ($shopQuery) => $shopQuery->where('status', 'open'));
        }

        return $query;
    }

    public function appraisalRatioPercent(): ?float
    {
        if ((int) $this->appraisal_price <= 0) {
            return null;
        }

        return round(((int) $this->listing_price / (int) $this->appraisal_price) * 100, 1);
    }
}
