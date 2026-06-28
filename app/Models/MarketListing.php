<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketListing extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'remaining_quantity' => 'integer',
        'unit_price' => 'integer',
        'listing_fee' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function isNpcListing(): bool
    {
        return (string) ($this->seller_type ?? 'character') === 'npc';
    }

    public function seller()
    {
        return $this->belongsTo(Character::class, 'seller_character_id');
    }

    public function sellerNpc()
    {
        return $this->belongsTo(NpcMaster::class, 'seller_npc_id', 'npc_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function transactions()
    {
        return $this->hasMany(MarketTransaction::class, 'listing_id');
    }

    public function scopeActive($query)
    {
        return $query
            ->where('status', 'active')
            ->where('remaining_quantity', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeMarketSellerEligible($query)
    {
        return $query->where(function ($query) {
            $query->where('seller_type', 'character')
                ->orWhere(function ($query) {
                    $query->where('seller_type', 'npc')
                        ->whereHas('sellerNpc', fn ($npcQuery) => $npcQuery->marketSellerEligible());
                });
        });
    }
}
