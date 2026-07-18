<?php

namespace App\Services\Battle;

use Illuminate\Container\Container;

class DamageCalculator
{
    private const DUEL_DEFENSE_RATE = 0.50;
    private const DUEL_MIN_DAMAGE_RATE = 0.20;
    private const DUEL_CRITICAL_MULTIPLIER = 1.35;
    private const DUEL_VARIANCE_MIN = 90;
    private const DUEL_VARIANCE_MAX = 110;
    private const RANK_BATTLE_ATTACK_RATE = 0.56;
    private const RANK_BATTLE_DEFENSE_RATE = 0.30;
    private const RANK_BATTLE_PRESSURE_RATE = 0.16;
    private const RANK_BATTLE_MIN_HP_RATE = 0.045;
    private const RANK_BATTLE_MIN_ATTACK_RATE = 0.18;
    private const RANK_BATTLE_NORMAL_FLOOR_RATE = 0.04;
    private const RANK_BATTLE_SKILL_TOTAL_FLOOR_RATE = 0.10;
    private const RANK_BATTLE_NORMAL_CAP_RATE = 0.18;
    private const RANK_BATTLE_NORMAL_CRITICAL_CAP_RATE = 0.22;
    private const RANK_BATTLE_SKILL_TOTAL_CAP_RATE = 0.35;
    private const RANK_BATTLE_SKILL_CRITICAL_TOTAL_CAP_RATE = 0.40;
    private const RANK_BATTLE_CRITICAL_MULTIPLIER = 1.18;
    private const RANK_BATTLE_VARIANCE_MIN = 96;
    private const RANK_BATTLE_VARIANCE_MAX = 104;

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
        int $maxHitRate = 98
    ): bool
    {
        $baseHitRate = 90;
        $baseHitRate = $baseHitRate * ($skillAccuracy / 100);

        $agiDiff = $attacker->effectiveAgi() - $defender->effectiveAgi();
        $hitRate = $baseHitRate + ($agiDiff * $agiFactor);

        if ($hitRate < $minHitRate) $hitRate = $minHitRate;
        if ($hitRate > $maxHitRate) $hitRate = $maxHitRate;

        return rand(1, 100) <= $hitRate;
    }

    /**
     * クリティカル判定
     */
    public function isCritical(BattleActor $attacker, BattleActor $defender): bool
    {
        $baseCrit = 5;
        $lukDiff = $attacker->luk - $defender->luk;
        $critRate = $baseCrit + ($lukDiff * 0.2);

        if ($critRate < 1) $critRate = 1;
        if ($critRate > 30) $critRate = 30; // 上限30%

        return rand(1, 100) <= $critRate;
    }

    public function isDuelCritical(BattleActor $attacker, BattleActor $defender, float $bonusRate = 0.0): bool
    {
        $critRate = 5.0 + $bonusRate + (($attacker->luk - $defender->luk) * 0.05);
        $critRate = max(3.0, min(20.0, $critRate));

        return rand(1, 100) <= $critRate;
    }

    public function isRankBattleCritical(BattleActor $attacker, BattleActor $defender, float $bonusRate = 0.0): bool
    {
        $critRate = 3.0 + $bonusRate + (($attacker->luk - $defender->luk) * 0.03);
        $critRate = max(2.0, min(12.0, $critRate));

        return rand(1, 100) <= $critRate;
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

        $rawPower = $attackPower * ($skillPower / 100);
        $baseDamage = max(
            1,
            $rawPower * self::DUEL_MIN_DAMAGE_RATE,
            $rawPower - ($effectiveDefense * self::DUEL_DEFENSE_RATE)
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

        return max(1, (int) floor($baseDamage * $variance));
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
        int $hitCount = 1
    ): int {
        $attackType = $attackType === 'magical' ? 'magical' : 'physical';
        $attackPower = $overrideAtk ?? ($attackType === 'magical' ? $attacker->effectiveMag() : $attacker->effectiveStr());
        $def = $overrideDef ?? $defender->effectiveDef();
        $spr = $overrideSpr ?? $defender->effectiveSpr();

        $effectiveDefense = $attackType === 'magical'
            ? ($spr * 0.72) + ($def * 0.28)
            : ($def * 0.72) + ($spr * 0.28);

        $powerMultiplier = $this->rankBattlePowerMultiplier($skillPower);
        $statDamage = ($attackPower * self::RANK_BATTLE_ATTACK_RATE)
            - ($effectiveDefense * self::RANK_BATTLE_DEFENSE_RATE);
        $pressureDamage = max(0, $attackPower - $effectiveDefense) * self::RANK_BATTLE_PRESSURE_RATE;
        $minimumDamage = max(
            1,
            $defender->maxHp * self::RANK_BATTLE_MIN_HP_RATE,
            $attackPower * self::RANK_BATTLE_MIN_ATTACK_RATE
        );

        $baseDamage = max($minimumDamage, $statDamage + $pressureDamage) * $powerMultiplier;

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
        $damage = max(1, (int) floor($baseDamage * $variance));

        if ($isSkill) {
            $damage = max($damage, $this->rankBattleSkillDamageFloor($defender, $hitCount));
        } else {
            $damage = max($damage, $this->rankBattleNormalDamageFloor($defender));
        }

        return min($damage, $this->rankBattleDamageCap($defender, $isSkill, $isCritical, $hitCount));
    }

    private function rankBattlePowerMultiplier(int $skillPower): float
    {
        $rawMultiplier = max(0.1, $skillPower / 100);

        if ($rawMultiplier <= 1.0) {
            return $rawMultiplier;
        }

        return min(1.85, 1.0 + (($rawMultiplier - 1.0) * 0.42));
    }

    private function rankBattleDamageCap(BattleActor $defender, bool $isSkill, bool $isCritical, int $hitCount): int
    {
        if (!$isSkill) {
            $capRate = $isCritical
                ? self::RANK_BATTLE_NORMAL_CRITICAL_CAP_RATE
                : self::RANK_BATTLE_NORMAL_CAP_RATE;

            return max(1, (int) floor($defender->maxHp * $capRate));
        }

        $hitCount = max(1, $hitCount);
        $totalCapRate = $isCritical
            ? self::RANK_BATTLE_SKILL_CRITICAL_TOTAL_CAP_RATE
            : self::RANK_BATTLE_SKILL_TOTAL_CAP_RATE;

        return max(1, (int) floor($defender->maxHp * ($totalCapRate / $hitCount)));
    }

    private function rankBattleNormalDamageFloor(BattleActor $defender): int
    {
        return max(1, (int) floor($defender->maxHp * self::RANK_BATTLE_NORMAL_FLOOR_RATE));
    }

    private function rankBattleSkillDamageFloor(BattleActor $defender, int $hitCount): int
    {
        $hitCount = max(1, $hitCount);

        return max(1, (int) floor($defender->maxHp * (self::RANK_BATTLE_SKILL_TOTAL_FLOOR_RATE / $hitCount)));
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
        $stat = $attackType === 'magical' ? $defender->spr : $defender->def;
        $condition = $attackType === 'magical' ? 'spr_down' : 'def_down';

        // 新式では防御0をそのまま扱い、基礎ダメージを攻撃力と一致させる。
        return max(0.0, floor($stat * (1 - $defender->conditionRate($condition))));
    }

    private function calculatePveEnemyPercentageDamage(int $attackPower, float $defense, BattleActor $defender, int $skillPower, bool $isCritical): int
    {
        $attackPower = max(1, $attackPower);
        $effectiveDefense = max(0.0, $defense);
        if ($isCritical) {
            $effectiveDefense /= 2;
        }

        $coefficient = max(0.0, (float) $this->battleConfig('pve_enemy_percentage_defense.defense_coefficient', 0.8));
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

    private function battleConfig(string $key, mixed $default): mixed
    {
        $container = Container::getInstance();
        if (! $container->bound('config')) {
            return $default;
        }

        return $container->make('config')->get('battle.' . $key, $default);
    }
}
