<?php

namespace App\Services\Battle;

use App\Models\Skill;

interface PvPRoomRuleInterface
{
    public function onBattleStart(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
    ): void;

    public function modifyInitiative(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        int $attackerSpeed,
        int $defenderSpeed,
        bool $defaultAttackerFirst,
    ): bool;

    public function modifyLukPowerContribution(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        int $contribution,
    ): int;

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
    ): array;

    public function modifyFinalDamage(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
        int $hitIndex = 1,
        int $hitCount = 1,
    ): int;

    public function modifyHealing(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $amount,
        int|string|null $sourceId = null,
    ): int;

    public function onActualHpLoss(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $actualHpLoss,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
    ): void;

    public function onActionEnd(
        BattleActor $actor,
        BattleActor $opponent,
        BattleState $state,
    ): void;
}
