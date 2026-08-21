<?php

namespace App\Services\Battle\RoomRules;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\PvPRoomRuleInterface;

final class ReverseTimePvPRoomRule implements PvPRoomRuleInterface
{
    private const DAMAGE_BONUS_RATE_PER_STEP = 0.08;

    private const MAX_DAMAGE_BONUS_RATE = 0.40;

    private const MAX_DAMAGE_BONUS_STEPS = 5;

    public function onBattleStart(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
    ): void {}

    public function modifyInitiative(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        int $attackerSpeed,
        int $defenderSpeed,
        bool $defaultAttackerFirst,
    ): bool {
        return $attackerSpeed <= $defenderSpeed;
    }

    public function modifyLukPowerContribution(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        int $contribution,
    ): int {
        return $contribution;
    }

    /**
     * @param array{attack:?int,def:?int,spr:?int} $overrides
     * @return array{attack:?int,def:?int,spr:?int}
     */
    public function modifyDamageStatOverrides(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        string $attackType,
        DamageSourceType $sourceType,
        ?Skill $skill,
        array $overrides,
    ): array {
        return $overrides;
    }

    public function modifyFinalDamage(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
        int $hitIndex = 1,
        int $hitCount = 1,
    ): int {
        if ($damage <= 0
            || $source === null
            || $source === $target
            || $sourceType === DamageSourceType::RECOIL
        ) {
            return $damage;
        }

        $sourceAgi = max(0, $source->effectiveAgi());
        $targetAgi = max(0, $target->effectiveAgi());
        if ($sourceAgi >= $targetAgi) {
            return $damage;
        }

        // floor((difference / target) / 0.10), expressed with integers so
        // exact 10% and 20% boundaries cannot slip below a step in floating point.
        $steps = intdiv(
            ($targetAgi - $sourceAgi) * 10,
            max(1, $targetAgi),
        );
        $steps = min(self::MAX_DAMAGE_BONUS_STEPS, $steps);
        $bonusRate = min(
            self::MAX_DAMAGE_BONUS_RATE,
            $steps * self::DAMAGE_BONUS_RATE_PER_STEP,
        );

        return max(0, (int) floor(($damage * (1.0 + $bonusRate)) + 1.0e-9));
    }

    public function modifyHealing(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $amount,
        int|string|null $sourceId = null,
    ): int {
        return $amount;
    }

    public function onActualHpLoss(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $actualHpLoss,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
    ): void {}

    public function onActionEnd(
        BattleActor $actor,
        BattleActor $opponent,
        BattleState $state,
    ): void {}
}
