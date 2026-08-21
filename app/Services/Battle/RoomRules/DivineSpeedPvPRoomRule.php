<?php

namespace App\Services\Battle\RoomRules;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\PvPRoomRuleInterface;

final class DivineSpeedPvPRoomRule implements PvPRoomRuleInterface
{
    private const ADVANTAGE_TO_DAMAGE_RATE = 0.40;

    private const MAX_DAMAGE_BONUS_RATE = 0.60;

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
        return $defaultAttackerFirst;
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
        if ($sourceAgi <= $targetAgi) {
            return $damage;
        }

        $advantageRate = ($sourceAgi - $targetAgi) / max(1, $targetAgi);
        $bonusRate = min(
            self::MAX_DAMAGE_BONUS_RATE,
            $advantageRate * self::ADVANTAGE_TO_DAMAGE_RATE,
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
