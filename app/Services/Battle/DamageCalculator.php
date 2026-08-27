<?php

namespace App\Services\Battle;

use Illuminate\Container\Container;

class DamageCalculator
{
    private const DUEL_DEFENSE_RATE = 0.50;
    private const DUEL_MIN_DAMAGE_RATE = 0.20;
    private const DUEL_CRITICAL_MULTIPLIER = 1.50;
    private const DUEL_VARIANCE_MIN = 90;
    private const DUEL_VARIANCE_MAX = 110;
    private const RANK_BATTLE_ATTACK_RATE = 0.56;
    private const RANK_BATTLE_DEFENSE_RATE = 0.30;
    private const RANK_BATTLE_PRESSURE_RATE = 0.16;
    private const RANK_BATTLE_SOFT_MIN_ATTACK_RATE = 0.18;
    private const RANK_BATTLE_SOFT_MIN_BALANCE_RATIO = 1.267;
    private const RANK_BATTLE_CRITICAL_MULTIPLIER = 1.50;
    private const RANK_BATTLE_VARIANCE_MIN = 96;
    private const RANK_BATTLE_VARIANCE_MAX = 104;

    /**
     * 管理者の検証画面へ、実際のランク戦damage式と同じ係数を公開する。
     *
     * @return array{
     *     attack_rate: float,
     *     defense_rate: float,
     *     pressure_rate: float,
     *     soft_min_attack_rate: float,
     *     soft_min_balance_ratio: float,
     *     critical_multiplier: float,
     *     variance_min: int,
     *     variance_max: int
     * }
     */
    public function rankBattleFormulaParameters(): array
    {
        return [
            'attack_rate' => self::RANK_BATTLE_ATTACK_RATE,
            'defense_rate' => self::RANK_BATTLE_DEFENSE_RATE,
            'pressure_rate' => self::RANK_BATTLE_PRESSURE_RATE,
            'soft_min_attack_rate' => self::RANK_BATTLE_SOFT_MIN_ATTACK_RATE,
            'soft_min_balance_ratio' => self::RANK_BATTLE_SOFT_MIN_BALANCE_RATIO,
            'critical_multiplier' => self::RANK_BATTLE_CRITICAL_MULTIPLIER,
            'variance_min' => self::RANK_BATTLE_VARIANCE_MIN,
            'variance_max' => self::RANK_BATTLE_VARIANCE_MAX,
        ];
    }

    /**
     * 命中判定
     * @return bool 命中したかどうか
     */
    public function isHit(
        BattleActor $attacker,
        BattleActor $defender,
        int $skillAccuracy = 100,
        float $agiFactor = 0.5,
        int $minHitRate = 70,
        int $maxHitRate = 98,
        float $accuracyDelta = 0.0,
    ): bool
    {
        $hitChance = $this->calculateHitChance(
            $attacker,
            $defender,
            $skillAccuracy,
            $agiFactor,
            $minHitRate,
            $maxHitRate,
        );
        $hitChance = max($minHitRate, min($maxHitRate, $hitChance + $accuracyDelta));

        return rand(1, 100) <= $hitChance;
    }

    /**
     * 既存の命中式だけを計算し、乱数や戦闘状態を変更しない。
     */
    public function calculateHitChance(
        BattleActor $attacker,
        BattleActor $defender,
        int $skillAccuracy = 100,
        float $agiFactor = 0.5,
        int $minHitRate = 70,
        int $maxHitRate = 98
    ): float {
        $baseHitRate = 90;
        $baseHitRate = $baseHitRate * ($skillAccuracy / 100);

        $agiDiff = $attacker->effectiveAgi() - $defender->effectiveAgi();
        $hitRate = $baseHitRate + ($agiDiff * $agiFactor);

        if ($hitRate < $minHitRate) $hitRate = $minHitRate;
        if ($hitRate > $maxHitRate) $hitRate = $maxHitRate;

        return $hitRate;
    }

    /**
     * クリティカル判定
     */
    public function isCritical(BattleActor $attacker, BattleActor $defender, float $bonusRate = 0.0): bool
    {
        return rand(1, 100) <= $this->criticalChance($attacker, $defender, $bonusRate);
    }

    /**
     * PvEの正式会心率を乱数消費なしで返す。
     */
    public function criticalChance(BattleActor $attacker, BattleActor $defender, float $bonusRate = 0.0): float
    {
        $critRate = 5 + $bonusRate
            + (($attacker->effectiveLuk() - $defender->effectiveLuk()) * 0.2);

        return max(1.0, min(30.0, $critRate));
    }

    public function isDuelCritical(BattleActor $attacker, BattleActor $defender, float $bonusRate = 0.0): bool
    {
        return rand(1, 100) <= $this->duelCriticalChance($attacker, $defender, $bonusRate);
    }

    public function duelCriticalChance(BattleActor $attacker, BattleActor $defender, float $bonusRate = 0.0): float
    {
        $critRate = 5.0 + $bonusRate + (($attacker->effectiveLuk() - $defender->effectiveLuk()) * 0.05);

        return max(3.0, min(20.0, $critRate));
    }

    public function isRankBattleCritical(BattleActor $attacker, BattleActor $defender, float $bonusRate = 0.0): bool
    {
        return rand(1, 100) <= $this->rankBattleCriticalChance($attacker, $defender, $bonusRate);
    }

    public function rankBattleCriticalChance(BattleActor $attacker, BattleActor $defender, float $bonusRate = 0.0): float
    {
        $critRate = 3.0 + $bonusRate + (($attacker->effectiveLuk() - $defender->effectiveLuk()) * 0.03);

        return max(2.0, min(12.0, $critRate));
    }

    public function calculateDuelDamage(
        BattleActor $attacker,
        BattleActor $defender,
        string $attackType,
        int $skillPower = 100,
        bool $isCritical = false,
        float $affinityMultiplier = 1.0,
        ?int $overrideAtk = null,
        ?int $overrideDef = null,
        ?int $overrideSpr = null
    ): int {
        $attackType = $attackType === 'magical' ? 'magical' : 'physical';
        $attackPower = $overrideAtk ?? ($attackType === 'magical' ? $attacker->effectiveMag() : $attacker->effectiveStr());
        $def = $overrideDef ?? $defender->effectiveDef();
        $spr = $overrideSpr ?? $defender->effectiveSpr();

        $effectiveDefense = $attackType === 'magical'
            ? ($spr * 0.7) + ($def * 0.3)
            : ($def * 0.7) + ($spr * 0.3);

        $baseDamage = max(
            1,
            $attackPower * self::DUEL_MIN_DAMAGE_RATE,
            $attackPower - ($effectiveDefense * self::DUEL_DEFENSE_RATE)
        );

        if ($isCritical) {
            $baseDamage *= self::DUEL_CRITICAL_MULTIPLIER;
        }

        $baseDamage *= $affinityMultiplier;

        if ($defender->isDefending) {
            $baseDamage *= 0.5;
        }
        if ($defender->damageReductionRate > 0) {
            $baseDamage *= (1 - ($defender->damageReductionRate / 100));
        }

        $variance = rand(self::DUEL_VARIANCE_MIN, self::DUEL_VARIANCE_MAX) / 100;
        $normalEquivalentDamage = max(1, (int) floor($baseDamage * $variance));

        return $this->applyDisplayedPower($normalEquivalentDamage, $skillPower);
    }

    public function calculateRankBattleDamage(
        BattleActor $attacker,
        BattleActor $defender,
        string $attackType,
        int $skillPower = 100,
        bool $isCritical = false,
        float $affinityMultiplier = 1.0,
        ?int $overrideAtk = null,
        ?int $overrideDef = null,
        ?int $overrideSpr = null,
        bool $isSkill = false,
        int $hitCount = 1,
        bool $minimumDamageGuaranteeEnabled = true,
        bool $damageCapEnabled = true,
        float $baseDamageMultiplier = 1.0,
        float $additionalDefenseIgnoreRate = 0.0,
    ): int {
        $attackType = $attackType === 'magical' ? 'magical' : 'physical';
        $attackPower = $overrideAtk ?? ($attackType === 'magical' ? $attacker->effectiveMag() : $attacker->effectiveStr());
        $def = $overrideDef ?? $defender->effectiveDef();
        $spr = $overrideSpr ?? $defender->effectiveSpr();

        $effectiveDefense = $attackType === 'magical'
            ? ($spr * 0.72) + ($def * 0.28)
            : ($def * 0.72) + ($spr * 0.28);
        if ($additionalDefenseIgnoreRate > 0.0) {
            $effectiveDefense *= 1 - min(0.50, $additionalDefenseIgnoreRate);
        }

        $baseDamage = $this->rankBattleRecommendedBaseDamage($attackPower, $effectiveDefense)
            * max(0.0, $baseDamageMultiplier);

        if ($isCritical) {
            $baseDamage *= self::RANK_BATTLE_CRITICAL_MULTIPLIER;
        }

        $baseDamage *= $affinityMultiplier;

        if ($defender->isDefending) {
            $baseDamage *= 0.5;
        }
        if ($defender->damageReductionRate > 0) {
            $baseDamage *= (1 - ($defender->damageReductionRate / 100));
        }

        $variance = rand(self::RANK_BATTLE_VARIANCE_MIN, self::RANK_BATTLE_VARIANCE_MAX) / 100;
        $normalEquivalentDamage = max(1, (int) floor($baseDamage * $variance));

        return $this->applyDisplayedPower($normalEquivalentDamage, $skillPower);
    }

    /**
     * 既存の正式ダメージ式から乱数だけを除いた比較値。
     * 実ダメージを確定する用途ではなく、同一行動内の物理／魔法経路選択にのみ使う。
     */
    public function estimateJobArtDamage(
        BattleActor $attacker,
        BattleActor $defender,
        string $attackType,
        string $battleType,
        int $skillPower,
        int $hitCount = 1,
        bool $isCritical = false,
        bool $minimumDamageGuaranteeEnabled = true,
        float $baseDamageMultiplier = 1.0,
        float $additionalDefenseIgnoreRate = 0.0,
    ): int {
        $total = 0;
        foreach (JobArtHitPower::split($skillPower, $hitCount) as $hitPower) {
            $total += $this->estimateJobArtHitDamage(
                $attacker,
                $defender,
                $attackType,
                $battleType,
                $hitPower,
                max(1, $hitCount),
                $isCritical,
                $minimumDamageGuaranteeEnabled,
                $baseDamageMultiplier,
                $additionalDefenseIgnoreRate,
            );
        }

        return $total;
    }

    private function estimateJobArtHitDamage(
        BattleActor $attacker,
        BattleActor $defender,
        string $attackType,
        string $battleType,
        int $skillPower,
        int $hitCount,
        bool $isCritical,
        bool $minimumDamageGuaranteeEnabled,
        float $baseDamageMultiplier,
        float $additionalDefenseIgnoreRate,
    ): int {
        $attackType = $attackType === 'magical' ? 'magical' : 'physical';
        if ($battleType === 'champ') {
            $attackPower = $attackType === 'magical' ? $attacker->effectiveMag() : $attacker->effectiveStr();
            $def = $defender->effectiveDef();
            $spr = $defender->effectiveSpr();
            $effectiveDefense = $attackType === 'magical'
                ? ($spr * 0.7) + ($def * 0.3)
                : ($def * 0.7) + ($spr * 0.3);
            $damage = max(
                1,
                $attackPower * self::DUEL_MIN_DAMAGE_RATE,
                $attackPower - ($effectiveDefense * self::DUEL_DEFENSE_RATE),
            );
            if ($isCritical) {
                $damage *= self::DUEL_CRITICAL_MULTIPLIER;
            }
            if ($defender->isDefending) {
                $damage *= 0.5;
            }
            if ($defender->damageReductionRate > 0) {
                $damage *= (1 - ($defender->damageReductionRate / 100));
            }

            return $this->applyDisplayedPower(max(1, (int) floor($damage)), $skillPower);
        }

        if (in_array($battleType, ['pvp', 'arena_npc'], true)) {
            $attackPower = $attackType === 'magical' ? $attacker->effectiveMag() : $attacker->effectiveStr();
            $def = $defender->effectiveDef();
            $spr = $defender->effectiveSpr();
            $effectiveDefense = $attackType === 'magical'
                ? ($spr * 0.72) + ($def * 0.28)
                : ($def * 0.72) + ($spr * 0.28);
            if ($additionalDefenseIgnoreRate > 0.0) {
                $effectiveDefense *= 1 - min(0.50, $additionalDefenseIgnoreRate);
            }
            $damage = $this->rankBattleRecommendedBaseDamage($attackPower, $effectiveDefense)
                * max(0.0, $baseDamageMultiplier);
            if ($isCritical) {
                $damage *= self::RANK_BATTLE_CRITICAL_MULTIPLIER;
            }
            if ($defender->isDefending) {
                $damage *= 0.5;
            }
            if ($defender->damageReductionRate > 0) {
                $damage *= (1 - ($defender->damageReductionRate / 100));
            }

            return $this->applyDisplayedPower(max(1, (int) floor($damage)), $skillPower);
        }

        $attackPower = $attackType === 'magical' ? $attacker->effectiveMag() : $attacker->effectiveStr();
        $defense = $attackType === 'magical' ? $defender->effectiveSpr() : $defender->effectiveDef();
        if ($isCritical) {
            $defense = (int) ($defense * 0.5);
        }
        $damage = max(1, $attackPower - ($defense / 2)) * ($skillPower / 100);
        if ($isCritical) {
            $damage *= 1.5;
        }
        if ($defender->isDefending) {
            $damage *= 0.5;
        }
        if ($defender->damageReductionRate > 0) {
            $damage *= (1 - ($defender->damageReductionRate / 100));
        }

        return max(1, (int) floor($damage));
    }

    private function applyDisplayedPower(int $normalEquivalentDamage, int $skillPower): int
    {
        return max(
            1,
            intdiv($normalEquivalentDamage * max(0, $skillPower), 100),
        );
    }

    private function rankBattleRecommendedBaseDamage(float $attackPower, float $effectiveDefense): float
    {
        $rawDamage = ($attackPower * self::RANK_BATTLE_ATTACK_RATE)
            - ($effectiveDefense * self::RANK_BATTLE_DEFENSE_RATE)
            + (max(0.0, $attackPower - $effectiveDefense) * self::RANK_BATTLE_PRESSURE_RATE);
        $softBoundary = $attackPower
            * self::RANK_BATTLE_SOFT_MIN_ATTACK_RATE
            * min(
                1.0,
                (self::RANK_BATTLE_SOFT_MIN_BALANCE_RATIO * $attackPower) / max(1.0, $effectiveDefense),
            );

        return max(1.0, $rawDamage, $softBoundary);
    }

    /**
     * 物理ダメージ計算
     */
    public function calculatePhysicalDamage(BattleActor $attacker, BattleActor $defender, int $skillPower = 100, bool $isCritical = false, ?int $overrideAtk = null, ?int $overrideDef = null): int
    {
        $atk = $overrideAtk ?? $attacker->effectiveStr();
        $def = $overrideDef ?? $defender->effectiveDef();

        if ($this->usesPveEnemyPercentageDefense($attacker, $defender)) {
            if ($overrideDef === null) {
                $def = $this->effectivePercentageDefense($defender, 'physical');
            }

            return $this->calculatePveEnemyPercentageDamage($atk, $def, $defender, $skillPower, $isCritical);
        }

        if ($isCritical) {
            $def = (int)($def * 0.5); // クリティカル時は敵の防御力半減
        }

        $baseDamage = $atk - ($def / 2);
        if ($baseDamage < 1) $baseDamage = 1;

        // スキル威力補正
        $baseDamage = $baseDamage * ($skillPower / 100);

        // クリティカル補正
        if ($isCritical) {
            $baseDamage *= 1.5;
        }

        $randomModifier = rand(85, 115) / 100;
        $finalDamage = (int)($baseDamage * $randomModifier);

        // 防御状態の軽減
        if ($defender->isDefending) {
            $finalDamage = (int)($finalDamage * 0.5);
        }
        if ($defender->damageReductionRate > 0) {
            $finalDamage = (int)($finalDamage * (1 - ($defender->damageReductionRate / 100)));
        }

        return max(1, $finalDamage);
    }

    /**
     * 魔法ダメージ計算
     */
    public function calculateMagicalDamage(BattleActor $attacker, BattleActor $defender, int $skillPower = 100, bool $isCritical = false, ?int $overrideAtk = null, ?int $overrideDef = null): int
    {
        $atk = $overrideAtk ?? $attacker->effectiveMag();
        $def = $overrideDef ?? $defender->effectiveSpr();

        if ($this->usesPveEnemyPercentageDefense($attacker, $defender)) {
            if ($overrideDef === null) {
                $def = $this->effectivePercentageDefense($defender, 'magical');
            }

            return $this->calculatePveEnemyPercentageDamage($atk, $def, $defender, $skillPower, $isCritical);
        }

        if ($isCritical) {
            $def = (int)($def * 0.5);
        }

        $baseDamage = $atk - ($def / 2);
        if ($baseDamage < 1) $baseDamage = 1;

        $baseDamage = $baseDamage * ($skillPower / 100);

        if ($isCritical) {
            $baseDamage *= 1.5;
        }

        $randomModifier = rand(85, 115) / 100;
        $finalDamage = (int)($baseDamage * $randomModifier);

        // 防御状態の軽減
        if ($defender->isDefending) {
            $finalDamage = (int)($finalDamage * 0.5);
        }
        if ($defender->damageReductionRate > 0) {
            $finalDamage = (int)($finalDamage * (1 - ($defender->damageReductionRate / 100)));
        }

        return max(1, $finalDamage);
    }

    private function usesPveEnemyPercentageDefense(BattleActor $attacker, BattleActor $defender): bool
    {
        return (bool) $this->battleConfig('pve_enemy_percentage_defense.enabled', false)
            && ! $attacker->isPlayer
            && $defender->isPlayer;
    }

    private function effectivePercentageDefense(BattleActor $defender, string $attackType): float
    {
        return $attackType === 'magical'
            ? $defender->effectivePercentageSpr()
            : $defender->effectivePercentageDef();
    }

    private function calculatePveEnemyPercentageDamage(int $attackPower, float $defense, BattleActor $defender, int $skillPower, bool $isCritical): int
    {
        $attackPower = max(1, $attackPower);
        $effectiveDefense = max(0.0, $defense);
        if ($isCritical) {
            $effectiveDefense /= 2;
        }

        $coefficient = max(0.0, (float) $this->battleConfig('pve_enemy_percentage_defense.defense_coefficient', 3.5));
        $baseDamage = $this->calculatePveEnemyPercentageBaseDamage($attackPower, $effectiveDefense, $coefficient);

        $damage = $baseDamage * ($skillPower / 100);
        if ($isCritical) {
            $damage *= 1.5;
        }
        $damage *= rand(85, 115) / 100;

        if ($defender->isDefending) {
            $damage *= 0.5;
        }
        if ($defender->damageReductionRate > 0) {
            $damage *= (1 - ($defender->damageReductionRate / 100));
        }

        return max(1, (int) floor($damage));
    }

    private function calculatePveEnemyPercentageBaseDamage(int $attackPower, float $effectiveDefense, float $coefficient): float
    {
        return ($attackPower * $attackPower) / ($attackPower + ($coefficient * $effectiveDefense));
    }

    /**
     * 現行のPvE敵→プレイヤー式を逆算し、乱数100%時に目標ダメージとなる攻撃値を返す。
     * 国家戦魔導砲でも同じ正式式を使うための逆関数。
     */
    public function attackPowerForPveEnemyTargetDamage(int $targetDamage, int $defense): int
    {
        $targetDamage = max(1, $targetDamage);
        $defense = max(0, $defense);
        if (! (bool) $this->battleConfig('pve_enemy_percentage_defense.enabled', true)) {
            return max(1, (int) ceil(($defense / 2) + $targetDamage));
        }
        $coefficient = max(0.0, (float) $this->battleConfig('pve_enemy_percentage_defense.defense_coefficient', 3.5));
        $discriminant = ($targetDamage * $targetDamage) + (4 * $targetDamage * $coefficient * $defense);

        return max(1, (int) ceil(($targetDamage + sqrt($discriminant)) / 2));
    }

    private function battleConfig(string $key, mixed $default): mixed
    {
        $container = Container::getInstance();
        if (! $container->bound('config')) {
            return $default;
        }

        return $container->make('config')->get('battle.' . $key, $default);
    }
}
