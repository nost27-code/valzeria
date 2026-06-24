<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\MarketListing;
use App\Models\Material;

class MaterialMarketInfoService
{
    public function getInfo(Character $character, Material $material): array
    {
        $activeListings = MarketListing::query()
            ->active()
            ->where('listing_type', 'material')
            ->where('material_id', $material->id);

        $activeMarketQuantity = (clone $activeListings)->sum('remaining_quantity');
        $lowestPrice = (clone $activeListings)
            ->orderBy('unit_price')
            ->value('unit_price');

        $ownedQuantity = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('material_id', $material->id)
            ->value('quantity') ?? 0;

        return [
            'material' => $material,
            'owned_quantity' => (int) $ownedQuantity,
            'active_market_quantity' => (int) $activeMarketQuantity,
            'lowest_price' => $lowestPrice !== null ? (int) $lowestPrice : null,
            'npc_sell_price' => (int) ($material->npc_sell_price ?? $material->npc_sale_price ?? 0),
            'market_min_price' => $material->marketMinPrice(),
            'market_max_price' => $material->marketMaxPrice(),
            'usage_summary' => $this->usageSummary($material),
            'acquisition_summary' => $this->acquisitionSummary($material),
            'usage_tags' => $this->usageTags($material),
            'acquisition_tags' => $this->acquisitionTags($material),
            'market_hint' => $this->marketHint($material),
            'is_marketable' => $material->isMarketable(),
            'unavailable_reason' => $material->marketUnavailableReason(),
        ];
    }

    private function usageSummary(Material $material): string
    {
        if (filled($material->usage_summary)) {
            return (string) $material->usage_summary;
        }

        if (filled($material->main_use)) {
            return (string) $material->main_use . 'に使う素材です。';
        }

        return $material->isMarketable()
            ? '装備進化・強化や市場取引に使う素材です。'
            : '進行や特殊な用途に関わる素材です。';
    }

    private function acquisitionSummary(Material $material): string
    {
        if (filled($material->acquisition_summary)) {
            return (string) $material->acquisition_summary;
        }

        if (filled($material->obtain_method)) {
            return (string) $material->obtain_method;
        }

        return match ($material->material_type) {
            'regional_drop' => '対応する街や地域のダンジョンで入手できます。',
            'common_drop' => '通常探索で敵から入手できます。',
            default => '入手先は素材ごとの設定に従います。',
        };
    }

    private function usageTags(Material $material): array
    {
        $tags = $material->usageTags();
        if ($tags !== []) {
            return $tags;
        }

        return match ($material->material_type) {
            'regional_drop' => ['地域素材', '装備進化'],
            'common_drop' => ['通常素材', '装備進化'],
            default => filled($material->main_use) ? [(string) $material->main_use] : [],
        };
    }

    private function acquisitionTags(Material $material): array
    {
        $tags = $material->acquisitionTags();
        if ($tags !== []) {
            return $tags;
        }

        return match ($material->material_type) {
            'regional_drop' => ['地域ダンジョン', '通常探索'],
            'common_drop' => ['通常探索', '敵ドロップ'],
            default => [],
        };
    }

    private function marketHint(Material $material): string
    {
        if (filled($material->market_hint)) {
            return (string) $material->market_hint;
        }

        if (! $material->isMarketable()) {
            return $material->marketUnavailableReason();
        }

        return '必要になるまでは保管し、余りが出たら市場価格を見て出品すると判断しやすい素材です。';
    }
}
