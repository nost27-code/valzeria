<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_tradable' => 'boolean',
        'drop_rate' => 'decimal:2',
        'drop_first_clear_only' => 'boolean',
        'npc_sell_price' => 'integer',
        'market_min_price' => 'integer',
        'market_max_price' => 'integer',
        'source_area_id' => 'integer',
        'is_key_item' => 'boolean',
        'is_cash_item' => 'boolean',
        'usage_tags' => 'array',
        'acquisition_tags' => 'array',
        'display_order' => 'integer',
    ];

    public function scopeMarketable($query)
    {
        return $query
            ->where('is_tradable', true)
            ->where('trade_policy', 'marketable')
            ->where('is_key_item', false)
            ->where('is_cash_item', false);
    }

    public function marketMinPrice(): int
    {
        $explicit = (int) ($this->market_min_price ?? 0);
        if ($explicit > 0) {
            return $explicit;
        }

        return max(1, (int) ($this->npc_sell_price ?? $this->npc_sale_price ?? 0));
    }

    public function getMarketMinPrice(): int
    {
        return $this->marketMinPrice();
    }

    public function marketMaxPrice(): int
    {
        $explicit = (int) ($this->market_max_price ?? 0);
        if ($explicit > 0) {
            return max($explicit, $this->marketMinPrice());
        }

        $base = max(1, (int) ($this->npc_sell_price ?? $this->npc_sale_price ?? 1));

        return max($this->marketMinPrice(), $base * 5);
    }

    public function getMarketMaxPrice(): int
    {
        return $this->marketMaxPrice();
    }

    public function isMarketable(): bool
    {
        return (bool) ($this->is_tradable ?? false)
            && ($this->trade_policy ?? null) === 'marketable'
            && ! (bool) ($this->is_key_item ?? false)
            && ! (bool) ($this->is_cash_item ?? false);
    }

    public function usageTags(): array
    {
        return $this->usage_tags ?? [];
    }

    public function acquisitionTags(): array
    {
        return $this->acquisition_tags ?? [];
    }

    public function displayName(): string
    {
        $name = (string) ($this->name ?? '素材');

        if (strtoupper((string) ($this->rarity ?? '')) !== 'SR') {
            return $name;
        }

        return str_ends_with($name, '[SR]') ? $name : "{$name} [SR]";
    }

    public function marketUnavailableReason(): string
    {
        if ((bool) ($this->is_key_item ?? false)) {
            return '進行に関わる重要素材のため、市場では取引できません。';
        }

        if ((bool) ($this->is_cash_item ?? false)) {
            return '換金専用素材のため、市場では取引できません。';
        }

        if (! (bool) ($this->is_tradable ?? false) || ($this->trade_policy ?? null) !== 'marketable') {
            return 'この素材は市場で取引できません。';
        }

        return '';
    }
}
