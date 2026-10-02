<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class EquipmentMarketSellFilterService
{
    public function apply(Builder $query, array $filters): Builder
    {
        if (filled($filters['sell_name'] ?? null)) {
            $name = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['sell_name'])) . '%';
            $query->where(fn ($q) => $q
                ->whereHas('item', fn ($item) => $item->whereRaw("name LIKE ? ESCAPE '!'", [$name]))
                ->orWhereHas('affixPrefix', fn ($affix) => $affix->whereRaw("name LIKE ? ESCAPE '!'", [$name]))
                ->orWhereHas('affixSuffix', fn ($affix) => $affix->whereRaw("name LIKE ? ESCAPE '!'", [$name])));
        }

        $query->whereHas('item', function ($item) use ($filters) {
            if (filled($filters['sell_type'] ?? null)) {
                $item->where('type', $filters['sell_type']);
            }
            foreach (['sell_category' => ['weapon_category', 'armor_category'], 'sell_rank' => ['weapon_rank', 'armor_rank']] as $key => [$weaponColumn, $armorColumn]) {
                if (filled($filters[$key] ?? null)) {
                    $value = $filters[$key];
                    $item->where(fn ($q) => $q
                        ->where(fn ($weapon) => $weapon->where('type', 'weapon')->where($weaponColumn, $value))
                        ->orWhere(fn ($armor) => $armor->where('type', 'armor')->where($armorColumn, $value)));
                }
            }
        });

        return $query;
    }
}
