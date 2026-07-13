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

        $quality = (string) ($characterItem->affix_quality ?: 'normal');
        $qualityBps = (int) config("equipment_market.quality_multipliers_bps.{$quality}", 10000);
        $enhanceBps = (int) config('equipment_market.enhance_multipliers_bps.' . min(30, max(0, (int) $characterItem->enhance_level)), 10000);
        $bodyAppraisal = intdiv($rankValue * $qualityBps * $enhanceBps, self::BPS * self::BPS);
        [$traitAppraisal, $traitBreakdown, $traitCount] = $this->traitAppraisal($characterItem);
        $appraisal = $bodyAppraisal + $traitAppraisal;

        return [
            'body_appraisal_price' => $bodyAppraisal,
            'trait_appraisal_price' => $traitAppraisal,
            'trait_breakdown' => $traitBreakdown,
            'trait_count' => $traitCount,
            'appraisal_price' => $appraisal,
            'minimum_price' => intdiv($appraisal * (int) config('equipment_market.minimum_price_bps'), self::BPS),
            'maximum_price' => intdiv($appraisal * (int) config('equipment_market.maximum_price_bps'), self::BPS),
            'appraisal_version' => (int) config('equipment_market.appraisal_version', 2),
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

    private function traitAppraisal(CharacterItem $characterItem): array
    {
        $traits = [];

        if ($characterItem->affix_prefix_id) {
            $traits[] = [
                'key' => 'engraving',
                'label' => '銘',
                'value' => $this->traitAppraisalValue($characterItem->effectiveAffixPrefixLevel()),
            ];
        }
        if ($characterItem->affix_suffix_id) {
            $traits[] = [
                'key' => 'slayer',
                'label' => '特攻',
                'value' => $this->traitAppraisalValue($characterItem->effectiveAffixSuffixLevel()),
            ];
        }

        if (count($traits) === 0) {
            return [0, [], 0];
        }
        if (count($traits) === 1) {
            return [$traits[0]['value'], [[
                'key' => $traits[0]['key'],
                'label' => $traits[0]['label'],
                'appraisal_price' => $traits[0]['value'],
                'is_secondary' => false,
            ]], 1];
        }

        if ($traits[0]['value'] === $traits[1]['value']) {
            $secondary = intdiv($traits[1]['value'] * (int) config('equipment_market.secondary_trait_rate_bps'), self::BPS);

            return [$traits[0]['value'] + $secondary, [], 2];
        }

        usort($traits, fn (array $left, array $right): int => $right['value'] <=> $left['value']);
        $secondary = intdiv($traits[1]['value'] * (int) config('equipment_market.secondary_trait_rate_bps'), self::BPS);

        return [$traits[0]['value'] + $secondary, [
            [
                'key' => $traits[0]['key'],
                'label' => $traits[0]['label'],
                'appraisal_price' => $traits[0]['value'],
                'is_secondary' => false,
            ],
            [
                'key' => $traits[1]['key'],
                'label' => $traits[1]['label'],
                'appraisal_price' => $secondary,
                'is_secondary' => true,
            ],
        ], 2];
    }

    private function traitAppraisalValue(int $level): int
    {
        return (int) config('equipment_market.trait_appraisal_values.' . min(5, max(1, $level)), 0);
    }
}
