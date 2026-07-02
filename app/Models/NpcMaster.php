<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NpcMaster extends Model
{
    public const MARKET_SELLER_EXCLUDED_RANKS = ['hero', 'legend'];

    protected $table = 'npc_master';
    protected $primaryKey = 'npc_id';
    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function procurementRequests()
    {
        return $this->hasMany(NpcProcurementRequest::class, 'npc_id', 'npc_id');
    }

    public function materialStocks()
    {
        return $this->hasMany(NpcMaterialStock::class, 'npc_id', 'npc_id');
    }

    public function marketListings()
    {
        return $this->hasMany(MarketListing::class, 'seller_npc_id', 'npc_id')
            ->where('seller_type', 'npc');
    }

    public function relatedNpc()
    {
        return $this->belongsTo(self::class, 'related_npc_id', 'npc_id');
    }

    public function relatedByNpcs()
    {
        return $this->hasMany(self::class, 'related_npc_id', 'npc_id');
    }

    public function scopeMarketSellerEligible($query)
    {
        return $query->whereNotIn('npc_rank', self::MARKET_SELLER_EXCLUDED_RANKS);
    }

    public function isMarketSellerEligible(): bool
    {
        return ! in_array((string) $this->npc_rank, self::MARKET_SELLER_EXCLUDED_RANKS, true);
    }

    public function getImagePathAttribute(): string
    {
        return sprintf('images/npc/npc_%03d.webp', (int) $this->npc_id);
    }
}
