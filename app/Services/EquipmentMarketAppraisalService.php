<?php

namespace App\Services;

use App\Models\CharacterItem;
use RuntimeException;

class EquipmentMarketAppraisalService
{
    private const BPS = 10000;

    public function appraisal(CharacterItem $characterItem): array
    {
        $item = $characterItem->item;
        $rank = strtoupper((string) ($item?->weapon_rank ?? ''));
        $rankValue = (int) config("equipment_market.weapon_rank_values.{$rank}", 0);
        if ($rankValue <= 0) throw new RuntimeException('この武器は市場査定できません。');

        $prefixValue = $characterItem->affix_prefix_id ? $this->tierValue($characterItem->effectiveAffixPrefixLevel()) : 0;
        $suffixValue = $characterItem->affix_suffix_id ? $this->tierValue($characterItem->effectiveAffixSuffixLevel()) : 0;
        $quality = (string) ($characterItem->affix_quality ?: 'normal');
        $qualityBps = (int) config("equipment_market.quality_multipliers_bps.{$quality}", 10000);
        $enhanceBps = (int) config('equipment_market.enhance_multipliers_bps.' . min(3, max(0, (int) $characterItem->enhance_level)), 10000);
        $appraisal = intdiv(($rankValue + $prefixValue + $suffixValue) * $qualityBps * $enhanceBps, self::BPS * self::BPS);

        return [
            'appraisal_price' => $appraisal,
            'minimum_price' => intdiv($appraisal * (int) config('equipment_market.minimum_price_bps'), self::BPS),
            'maximum_price' => intdiv($appraisal * (int) config('equipment_market.maximum_price_bps'), self::BPS),
        ];
    }

    public function fee(int $salePrice, ?int $feeRateBps = null): int
    {
        return intdiv($salePrice * ($feeRateBps ?? (int) config('equipment_market.fee_rate_bps')), self::BPS);
    }

    public function sellerProceeds(int $salePrice, ?int $feeRateBps = null): int
    {
        return $salePrice - $this->fee($salePrice, $feeRateBps);
    }

    public function tierLabel(int $level): string
    {
        return [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V'][$level] ?? '-';
    }

    private function tierValue(int $level): int
    {
        return (int) config('equipment_market.affix_tier_values.' . min(5, max(1, $level)), 0);
    }
}
