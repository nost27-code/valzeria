<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\MarketListing;
use App\Models\MarketTransaction;
use App\Models\Material;
use Illuminate\Support\Facades\DB;

class MarketDemandService
{
    private const USAGE_SCORES = [
        '武器強化' => 10,
        '防具強化' => 10,
        '装備強化' => 10,
        '武具作成' => 10,
        '薬品調合' => 10,
        '回復薬' => 10,
        '納品' => 5,
        '素材交換' => 5,
        'ヴァルモン給餌' => 5,
        '地域素材' => 5,
    ];

    private const DEMAND_RANK_STRENGTH = [
        '不足' => 4,
        '高' => 3,
        '普通' => 2,
        '低' => 1,
    ];

    public function __construct(
        private readonly MaterialMarketInfoService $materialInfoService,
        private readonly NpcProcurementRequestService $npcProcurementRequestService
    )
    {
    }

    public function getDemandBoard(Character $character): array
    {
        $materials = Material::query()
            ->marketable()
            ->orderByRaw("CASE market_category WHEN 'normal' THEN 1 WHEN 'regional' THEN 2 ELSE 9 END")
            ->orderBy('display_order')
            ->orderBy('material_code')
            ->get();

        $stockRows = MarketListing::query()
            ->active()
            ->where('listing_type', 'material')
            ->select('material_id', DB::raw('SUM(remaining_quantity) as stock'), DB::raw('MIN(unit_price) as lowest_price'))
            ->groupBy('material_id')
            ->get()
            ->keyBy('material_id');

        $salesRows = MarketTransaction::query()
            ->where('listing_type', 'material')
            ->where('created_at', '>=', now()->subDay())
            ->select('material_id', DB::raw('SUM(quantity) as sold_quantity'))
            ->groupBy('material_id')
            ->pluck('sold_quantity', 'material_id');

        $ownedRows = CharacterMaterial::query()
            ->where('character_id', $character->id)
            ->where('quantity', '>', 0)
            ->pluck('quantity', 'material_id');

        $npcRequestCounts = $this->npcProcurementRequestService->getActiveRequestCountsByMaterial();

        // TODO: 市場取引量が増えたら日次集計テーブル化して、このリアルタイム集計を軽くする。
        return $materials
            ->map(function (Material $material) use ($character, $stockRows, $salesRows, $ownedRows, $npcRequestCounts) {
                $stock = (int) ($stockRows[$material->id]->stock ?? 0);
                $soldQuantity24h = (int) ($salesRows[$material->id] ?? 0);
                $activeNpcRequestCount = (int) ($npcRequestCounts[$material->id] ?? 0);
                $stockScore = $this->stockScore($stock);
                $salesScore = $this->salesScore($soldQuantity24h);
                $usageScore = $this->usageScore($material);
                $npcRequestScore = $activeNpcRequestCount > 0 ? 15 : 0;
                $demandScore = $stockScore + $salesScore + $usageScore + $npcRequestScore;
                $demandRank = $this->demandRank($demandScore);
                $info = $this->materialInfoService->getInfo($character, $material);

                return [
                    'material' => $material,
                    'owned_quantity' => (int) ($ownedRows[$material->id] ?? 0),
                    'active_market_quantity' => $stock,
                    'lowest_price' => isset($stockRows[$material->id]->lowest_price)
                        ? (int) $stockRows[$material->id]->lowest_price
                        : null,
                    'sold_quantity_24h' => $soldQuantity24h,
                    'stock_score' => $stockScore,
                    'sales_score' => $salesScore,
                    'usage_score' => $usageScore,
                    'npc_request_score' => $npcRequestScore,
                    'demand_score' => $demandScore,
                    'demand_rank' => $demandRank,
                    'rank_strength' => self::DEMAND_RANK_STRENGTH[$demandRank],
                    'active_npc_request_count' => $activeNpcRequestCount,
                    'demand_tags' => $this->demandTags($material, $stock, $soldQuantity24h, $activeNpcRequestCount),
                    'usage_summary' => $info['usage_summary'],
                    'acquisition_summary' => $info['acquisition_summary'],
                    'usage_tags' => $info['usage_tags'],
                    'acquisition_tags' => $info['acquisition_tags'],
                ];
            })
            ->sort(function (array $a, array $b) {
                return ($b['demand_score'] <=> $a['demand_score'])
                    ?: ($b['rank_strength'] <=> $a['rank_strength'])
                    ?: ($a['active_market_quantity'] <=> $b['active_market_quantity'])
                    ?: ($b['sold_quantity_24h'] <=> $a['sold_quantity_24h'])
                    ?: ((int) ($a['material']->display_order ?? 0) <=> (int) ($b['material']->display_order ?? 0))
                    ?: ((int) $a['material']->id <=> (int) $b['material']->id);
            })
            ->values()
            ->all();
    }

    private function stockScore(int $stock): int
    {
        if ($stock <= 0) {
            return 40;
        }

        if ($stock <= 20) {
            return 30;
        }

        if ($stock <= 50) {
            return 20;
        }

        if ($stock <= 100) {
            return 10;
        }

        return 0;
    }

    private function salesScore(int $soldQuantity): int
    {
        if ($soldQuantity >= 50) {
            return 30;
        }

        if ($soldQuantity >= 20) {
            return 20;
        }

        if ($soldQuantity >= 1) {
            return 10;
        }

        return 0;
    }

    private function usageScore(Material $material): int
    {
        $score = 0;

        foreach ($material->usageTags() as $tag) {
            $score += self::USAGE_SCORES[$tag] ?? 0;
        }

        if (($material->market_category ?? null) === 'regional') {
            $score += self::USAGE_SCORES['地域素材'];
        }

        return min(30, $score);
    }

    private function demandRank(int $score): string
    {
        if ($score >= 60) {
            return '不足';
        }

        if ($score >= 40) {
            return '高';
        }

        if ($score >= 20) {
            return '普通';
        }

        return '低';
    }

    private function demandTags(Material $material, int $stock, int $soldQuantity, int $activeNpcRequestCount): array
    {
        $tags = [];
        $usageTags = $material->usageTags();

        if ($stock <= 20) {
            $tags[] = '在庫不足';
        }

        if ($soldQuantity >= 20) {
            $tags[] = '売れ筋';
        }

        if ($this->hasAnyTag($usageTags, ['武器強化', '防具強化', '装備強化', '武具作成', '鍛冶'])) {
            $tags[] = '鍛冶需要';
        }

        if ($this->hasAnyTag($usageTags, ['薬品調合', '回復薬', '魔力水', '解毒薬'])) {
            $tags[] = '薬師需要';
        }

        if (in_array('納品', $usageTags, true)) {
            $tags[] = '納品向け';
        }

        if (($material->market_category ?? null) === 'regional') {
            $tags[] = '地域素材';
        }

        if ($activeNpcRequestCount > 0) {
            $tags[] = 'NPC依頼あり';
        }

        return array_values(array_unique($tags));
    }

    private function hasAnyTag(array $tags, array $needles): bool
    {
        return count(array_intersect($tags, $needles)) > 0;
    }
}
