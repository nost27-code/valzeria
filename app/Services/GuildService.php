<?php

namespace App\Services;

use App\Models\Character;

class GuildService
{
    private const DEFEAT_GOLD_LOSS_RATE = 0.10;

    private const RANKS = [
        ['name' => '伝説級後援者', 'threshold' => 100000000, 'normal_rate' => 0.05, 'danger_rate_75' => 0.08, 'danger_rate_100' => 0.12, 'fee_cap' => 50000],
        ['name' => '英雄級冒険者', 'threshold' => 50000000, 'normal_rate' => 0.05, 'danger_rate_75' => 0.09, 'danger_rate_100' => 0.14, 'fee_cap' => 80000],
        ['name' => '黒金冒険者', 'threshold' => 30000000, 'normal_rate' => 0.055, 'danger_rate_75' => 0.10, 'danger_rate_100' => 0.15, 'fee_cap' => 100000],
        ['name' => '白金冒険者', 'threshold' => 10000000, 'normal_rate' => 0.06, 'danger_rate_75' => 0.11, 'danger_rate_100' => 0.16, 'fee_cap' => 120000],
        ['name' => '金級冒険者', 'threshold' => 2000000, 'normal_rate' => 0.07, 'danger_rate_75' => 0.12, 'danger_rate_100' => 0.17, 'fee_cap' => 140000],
        ['name' => '銀級冒険者', 'threshold' => 500000, 'normal_rate' => 0.08, 'danger_rate_75' => 0.13, 'danger_rate_100' => 0.18, 'fee_cap' => 160000],
        ['name' => '銅級冒険者', 'threshold' => 100000, 'normal_rate' => 0.09, 'danger_rate_75' => 0.14, 'danger_rate_100' => 0.19, 'fee_cap' => 180000],
        ['name' => '一般冒険者', 'threshold' => 0, 'normal_rate' => 0.10, 'danger_rate_75' => 0.15, 'danger_rate_100' => 0.20, 'fee_cap' => 200000],
    ];

    public function rankByDonation(int $donationTotal): array
    {
        foreach (self::RANKS as $rank) {
            if ($donationTotal >= $rank['threshold']) {
                return $rank;
            }
        }

        return self::RANKS[array_key_last(self::RANKS)];
    }

    public function nextRank(int $donationTotal): ?array
    {
        $ascending = array_reverse(self::RANKS);
        foreach ($ascending as $rank) {
            if ($rank['threshold'] > $donationTotal) {
                return $rank;
            }
        }

        return null;
    }

    public function calculateRescueFee(int $gold, int $dangerRate, int $donationTotal): array
    {
        $rank = $this->rankByDonation($donationTotal);

        return array_merge($this->calculateDefeatGoldLoss($gold), [
            'rank_name' => $rank['name'],
            'fee_cap' => 0,
            'rank' => $rank,
        ]);
    }

    public function calculateDefeatGoldLoss(int $gold): array
    {
        $gold = max(0, $gold);
        $loss = min($gold, max(0, (int) floor($gold * self::DEFEAT_GOLD_LOSS_RATE)));

        return [
            'fee' => $loss,
            'amount' => $loss,
            'rate' => self::DEFEAT_GOLD_LOSS_RATE,
            'rate_label' => $this->formatRate(self::DEFEAT_GOLD_LOSS_RATE),
        ];
    }

    public function donate(Character $character, int $amount): array
    {
        return ['success' => false, 'message' => '冒険者協会の寄付機能は現在利用できません。'];
    }

    public function formatRate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 1), '0'), '.').'%';
    }
}
