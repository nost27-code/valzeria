<?php

namespace App\Services\Nation\Raid;

/** レイド固有の順序だけを実装し、既存DamageCalculatorへ逆流させない。 */
final class NationRaidDamageResolver
{
    public function __construct(private readonly NationRaidRules $rules) {}

    /**
     * @param  array{name:string,hits:list<array{type:string,power:int,defense_ignore?:float}>,effect:?string,can_be_guarded:bool}  $action
     */
    public function resolveEnemyAction(
        array $action,
        int $stage,
        string $form,
        int $turn,
        NationRaidPlayerSnapshot $player,
        NationRaidRandomSource $random,
        ?float $telegraphReductionOverride = null,
        float $additionalTelegraphReduction = 0.0,
        bool $suppressUniqueEffect = false,
        bool $blockAttachedInterference = false,
        float $defenseMultiplier = 1.0,
        float $spiritMultiplier = 1.0,
    ): NationRaidEnemyDamageResult {
        $stageParameters = $this->rules->stageParameters($stage);
        $formParameters = $this->rules->formParameters($form);
        $band = $this->rules->turnBand($turn);
        $hitTrace = [];
        $sum = 0;
        $anyHit = false;

        foreach ($action['hits'] as $index => $hit) {
            $accuracyRoll = $random->nextInt(1, 100);
            if ($accuracyRoll > $player->enemyHitChancePercent) {
                $hitTrace[] = $this->emptyHitTrace($index, $hit, 'miss');

                continue;
            }

            if ($player->enemyEvadeChancePercent > 0
                && $random->nextInt(1, 100) <= $player->enemyEvadeChancePercent) {
                $hitTrace[] = $this->emptyHitTrace($index, $hit, 'evade');

                continue;
            }

            $anyHit = true;
            $critical = $random->nextInt(1, 100) <= $player->enemyCriticalChancePercent;
            $variance = $random->nextInt(NationRaidRules::VARIANCE_MIN, NationRaidRules::VARIANCE_MAX);
            $attack = $hit['type'] === 'magical' ? $stageParameters['magic'] : $stageParameters['attack'];
            $defense = $hit['type'] === 'magical'
                ? $player->spirit * max(0.0, $spiritMultiplier)
                : $player->defense * max(0.0, $defenseMultiplier);
            $defense *= 1 - min(0.50, max(0.0, (float) ($hit['defense_ignore'] ?? 0.0)));
            if ($critical) {
                $defense /= 2;
            }

            $base = ($attack * $attack) / ($attack + (NationRaidRules::DEFENSE_COEFFICIENT * $defense));
            $damage = $base * ($hit['power'] / 100);
            if ($critical) {
                $damage *= NationRaidRules::CRITICAL_MULTIPLIER;
            }
            $damage *= $variance / 100;
            $damage *= $band['outgoing_multiplier'] * $formParameters['outgoing_multiplier'];
            $damage = max(0, (int) floor($damage));
            $sum += $damage;

            $hitTrace[] = [
                'index' => $index + 1,
                'type' => $hit['type'],
                'power' => $hit['power'],
                'outcome' => 'hit',
                'critical' => $critical,
                'variance' => $variance,
                'damage' => $damage,
            ];
        }

        // 追加直接damageを将来足す場合も、ここでsumへ加えてから1回だけclampする。
        $cap = (int) floor($player->maxHp * $band['action_cap_rate']);
        $afterCap = min($sum, $cap);
        $finalReduction = $telegraphReductionOverride !== null
            ? $telegraphReductionOverride
            : $player->finalDamageReductionRate + $additionalTelegraphReduction;
        $finalReduction = min(0.95, max(0.0, $finalReduction));
        $finalDamage = max(0, (int) floor($afterCap * (1 - $finalReduction)));

        $appliedEffects = [];
        $effect = $action['effect'] ?? null;
        $blockedByFortress = $blockAttachedInterference
            && $effect !== null
            && $this->isFortressBlockedEffect($effect);
        if ($anyHit && $finalDamage >= 1 && ! $suppressUniqueEffect && ! $blockedByFortress
            && $effect !== null) {
            $appliedEffects[] = (string) $effect;
        }

        return new NationRaidEnemyDamageResult(
            beforeCap: $sum,
            cap: $cap,
            afterCap: $afterCap,
            finalReductionRate: $finalReduction,
            finalDamage: $finalDamage,
            hits: $hitTrace,
            appliedEffects: $appliedEffects,
        );
    }

    /**
     * @param  list<array{kind:string,damage:int,hit_count:int,defense_ignore_50_damage?:?int}>  $sources
     * @return array{sources:list<array{kind:string,raw_damage:int,applied_damage:int,hit_count:int}>,total_damage:int,max_one_action_damage:int,incoming_reduction:float}
     */
    public function resolvePlayerAction(
        array $sources,
        int $turn,
        string $form,
        float $responseDamageMultiplier = 1.0,
        float $additionalBossReduction = 0.0,
        float $responseDefenseIgnoreRate = 0.0,
    ): array {
        $band = $this->rules->turnBand($turn);
        $formParameters = $this->rules->formParameters($form);
        $reduction = min(NationRaidRules::BOSS_INCOMING_REDUCTION_CAP, max(0.0, $band['incoming_reduction'] + $formParameters['incoming_reduction'] + $additionalBossReduction));
        $resolved = [];
        $total = 0;
        $oneAction = 0;

        foreach ($sources as $source) {
            $rawDamage = $responseDefenseIgnoreRate >= 0.50
                && isset($source['defense_ignore_50_damage'])
                && $source['defense_ignore_50_damage'] !== null
                    ? (int) $source['defense_ignore_50_damage']
                    : $source['damage'];
            $multiplier = in_array($source['kind'], [NationRaidRules::DAMAGE_DIRECT, NationRaidRules::DAMAGE_SIMULTANEOUS], true)
                ? $responseDamageMultiplier
                : 1.0;
            $applied = max(0, (int) floor($rawDamage * $multiplier * (1 - $reduction)));
            $resolved[] = [
                'kind' => $source['kind'],
                'raw_damage' => $rawDamage,
                'applied_damage' => $applied,
                'hit_count' => $source['hit_count'],
            ];
            $total += $applied;
            if (in_array($source['kind'], [NationRaidRules::DAMAGE_DIRECT, NationRaidRules::DAMAGE_SIMULTANEOUS], true)) {
                $oneAction += $applied;
            }
        }

        return [
            'sources' => $resolved,
            'total_damage' => $total,
            'max_one_action_damage' => $oneAction,
            'incoming_reduction' => $reduction,
        ];
    }

    /** @param array{type:string,power:int} $hit @return array{index:int,type:string,power:int,outcome:string,critical:bool,variance:int,damage:int} */
    private function emptyHitTrace(int $index, array $hit, string $outcome): array
    {
        return [
            'index' => $index + 1,
            'type' => $hit['type'],
            'power' => $hit['power'],
            'outcome' => $outcome,
            'critical' => false,
            'variance' => 0,
            'damage' => 0,
        ];
    }

    private function isFortressBlockedEffect(string $effect): bool
    {
        return in_array($effect, [
            'defense_down_10_two_actions',
            'healing_down_25_two_actions',
            'current_sp_down_8',
            'defense_spirit_healing_down_25_two_actions',
            'hp_sp_healing_down_50_two_actions',
            'drain_healing_down_50_one_action',
        ], true);
    }
}
