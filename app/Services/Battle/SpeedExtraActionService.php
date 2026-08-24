<?php

namespace App\Services\Battle;

/**
 * 敏捷差による追加行動（保証付き確率）を解決する。
 *
 * 先手・後手の判定は各BattleServiceの既存処理をそのまま使い、
 * ここでは「双方の通常行動が終わったあと、ラウンド末に1回だけ」
 * 追加行動を与えるかどうかだけを決める。
 */
class SpeedExtraActionService
{
    /** 敏捷倍率が1.0を超えた分に掛ける係数（パーセントポイント）。 */
    public const CHANCE_RATE_PER_RATIO = 50.0;

    public const MAX_CHANCE = 100.0;

    /**
     * 連続不発の許容量。`ceil(PITY_BUDGET / 発動率%)` 回続けて外すと次回が確定発動になる。
     * 小さくするほど保証が早く来る。
     */
    public const PITY_BUDGET = 200;

    /** 抽選の分解能。発動率の小数第2位まで扱う。 */
    private const ROLL_RESOLUTION = 10000;

    /**
     * 追加行動率（0.0〜100.0）。実効敏捷を使うため、敏捷低下の戦技が正しく効く。
     */
    public function calculateChance(BattleActor $actor, BattleActor $opponent): float
    {
        $ratio = $actor->effectiveAgi() / max(1, $opponent->effectiveAgi());

        return max(0.0, min(self::MAX_CHANCE, ($ratio - 1.0) * self::CHANCE_RATE_PER_RATIO));
    }

    /**
     * 確定発動までに許される連続失敗回数。発動率が0なら保証しない。
     */
    public function pityThreshold(float $chance): ?int
    {
        if ($chance <= 0.0) {
            return null;
        }

        return max(1, (int) ceil(self::PITY_BUDGET / $chance));
    }

    /**
     * 追加行動を与えるかどうかを判定し、保証カウンタを更新する。
     */
    public function shouldGrantExtraAction(BattleActor $actor, BattleActor $opponent): bool
    {
        $chance = $this->calculateChance($actor, $opponent);

        if ($chance <= 0.0) {
            return false;
        }

        if ($chance >= self::MAX_CHANCE) {
            $actor->extraActionMissCount = 0;

            return true;
        }

        // 保証判定は抽選より先に行い、「N回失敗したら次回確定」とする。
        $threshold = $this->pityThreshold($chance);
        if ($threshold !== null && $actor->extraActionMissCount >= $threshold) {
            $actor->extraActionMissCount = 0;

            return true;
        }

        if (random_int(1, self::ROLL_RESOLUTION) <= (int) round($chance * (self::ROLL_RESOLUTION / 100))) {
            $actor->extraActionMissCount = 0;

            return true;
        }

        $actor->extraActionMissCount++;

        return false;
    }

    public function activationLog(BattleActor $actor): string
    {
        $name = e($actor->name);

        return "<span class=\"text-cyan-700 font-extrabold\">【神速】圧倒的な敏捷により、{$name} は追加行動を得た！</span>";
    }
}
