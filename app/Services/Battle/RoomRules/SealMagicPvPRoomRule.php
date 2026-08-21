<?php

namespace App\Services\Battle\RoomRules;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\PvPAttackStatRouteResolver;
use App\Services\Battle\PvPRoomRuleInterface;

final class SealMagicPvPRoomRule implements PvPRoomRuleInterface
{
    private readonly PvPAttackStatRouteResolver $routeResolver;

    public function __construct(?PvPAttackStatRouteResolver $routeResolver = null)
    {
        $this->routeResolver = $routeResolver ?? new PvPAttackStatRouteResolver();
    }

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
        if ($this->routeResolver->resolve($attackType, $skill) === PvPAttackStatRouteResolver::MAG) {
            $overrides['attack'] = 0;
        }

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
        return $damage;
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
