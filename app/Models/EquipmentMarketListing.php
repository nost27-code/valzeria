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
        'recipient_character_id' => 'integer', 'nation_id_snapshot' => 'integer',
        'engraving_level' => 'integer', 'slayer_level' => 'integer', 'expires_at' => 'datetime',
        'sold_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function seller() { return $this->belongsTo(Character::class, 'seller_character_id'); }
    public function buyer() { return $this->belongsTo(Character::class, 'buyer_character_id'); }
    public function recipient() { return $this->belongsTo(Character::class, 'recipient_character_id'); }
    public function nation() { return $this->belongsTo(Nation::class, 'nation_id_snapshot'); }
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

    public function scopeVisibleTo($query, Character|int $character)
    {
        $characterId = $character instanceof Character ? $character->id : $character;
        $nationId = self::currentNationId((int) $characterId);

        return $query->where(function ($visible) use ($characterId, $nationId): void {
            $visible->whereNull('recipient_character_id')->whereNull('nation_id_snapshot')
                ->orWhere('recipient_character_id', $characterId)
                ->orWhere('seller_character_id', $characterId);
            if ($nationId !== null) {
                $visible->orWhere('nation_id_snapshot', $nationId);
            }
        });
    }

    public function isVisibleTo(Character|int $character): bool
    {
        $characterId = $character instanceof Character ? $character->id : $character;

        if ((int) $this->seller_character_id === (int) $characterId) return true;
        if ($this->nation_id_snapshot !== null) return self::currentNationId((int) $characterId) === (int) $this->nation_id_snapshot;
        if ($this->recipient_character_id !== null) return (int) $this->recipient_character_id === (int) $characterId;

        return true;
    }

    public function displayNameWithRank(): string
    {
        $name = (string) $this->display_name_snapshot;
        $rank = (string) $this->weapon_rank;
        if (($this->item_snapshot['item_type'] ?? null) === 'armor' && $rank !== '' && ! str_starts_with($name, '['.$rank.'] ')) {
            return '['.$rank.'] '.$name;
        }

        return $name;
    }

    private static function currentNationId(int $characterId): ?int
    {
        if (! config('features.nation_community_enabled', false)) return null;

        $nationId = NationMembership::query()->where('character_id', $characterId)->value('nation_id');

        return $nationId === null ? null : (int) $nationId;
    }

    public function appraisalRatioPercent(): ?float
    {
        if ((int) $this->appraisal_price <= 0) {
            return null;
        }

        return round(((int) $this->listing_price / (int) $this->appraisal_price) * 100, 1);
    }
}
