<?php

namespace App\Services\Battle\RoomRules;

use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\PvPRoomRuleInterface;

final class BurningLifePvPRoomRule implements PvPRoomRuleInterface
{
    private const MAX_STACK = 5;

    private const STACK_THRESHOLD_PERCENT = 15;

    private const ATTACK_MULTIPLIER_BASE = 0.50;

    private const ATTACK_MULTIPLIER_PER_STACK = 0.15;

    private const DEFENSE_MULTIPLIER_BASE = 1.40;

    private const DEFENSE_MULTIPLIER_PER_STACK = 0.08;

    private const HEALING_MULTIPLIER_BASE = 1.00;

    private const HEALING_MULTIPLIER_PER_STACK = 0.08;

    private const SELF_DAMAGE_BASELINE_MAX_HP_RATE = 0.02;

    private const SELF_DAMAGE_CURRENT_HP_RATE = 0.02;

    /** @var \WeakMap<BattleState, array<string, BurningLifeCombatantState>> */
    private \WeakMap $battleStates;

    public function __construct()
    {
        $this->battleStates = new \WeakMap;
    }

    public function onBattleStart(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
    ): void {
        $this->battleStates[$state] = [
            $state->actorKey($attacker) => new BurningLifeCombatantState($attacker->maxHp),
            $state->actorKey($defender) => new BurningLifeCombatantState($defender->maxHp),
        ];
    }

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
     * @param  array{attack:?int,def:?int,spr:?int}  $overrides
     * @return array{attack:int,def:int,spr:int}
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
        $attackerState = $this->combatantState($state, $attacker);
        $defenderState = $this->combatantState($state, $defender);
        $attackPower = $overrides['attack'] ?? match ($attackType) {
            'magical' => $attacker->effectiveMag(),
            default => $attacker->effectiveStr(),
        };
        $defense = $overrides['def'] ?? $defender->effectiveDef();
        $spirit = $overrides['spr'] ?? $defender->effectiveSpr();

        return [
            'attack' => $this->scaleStat(
                $attackPower,
                self::ATTACK_MULTIPLIER_BASE
                    + ($attackerState->stack * self::ATTACK_MULTIPLIER_PER_STACK),
            ),
            'def' => $this->scaleStat(
                $defense,
                self::DEFENSE_MULTIPLIER_BASE
                    - ($defenderState->stack * self::DEFENSE_MULTIPLIER_PER_STACK),
            ),
            'spr' => $this->scaleStat(
                $spirit,
                self::DEFENSE_MULTIPLIER_BASE
                    - ($defenderState->stack * self::DEFENSE_MULTIPLIER_PER_STACK),
            ),
        ];
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
        $targetState = $this->combatantState($state, $target);
        $multiplier = self::HEALING_MULTIPLIER_BASE
            - ($targetState->stack * self::HEALING_MULTIPLIER_PER_STACK);

        return max(0, (int) floor(($amount * $multiplier) + 1.0e-9));
    }

    public function onActualHpLoss(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $actualHpLoss,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
    ): void {
        if ($source === null
            || $source === $target
            || in_array($sourceType, [DamageSourceType::RECOIL, DamageSourceType::SELF_DAMAGE], true)
        ) {
            return;
        }

        $this->recordHpLoss($state, $target, $actualHpLoss);
    }

    public function onActionEnd(
        BattleActor $actor,
        BattleActor $opponent,
        BattleState $state,
    ): void {
        if ($actor->isDead()) {
            return;
        }

        $combatantState = $this->combatantState($state, $actor);
        $requestedSelfDamage = (int) floor(
            ($combatantState->baselineMaxHp * self::SELF_DAMAGE_BASELINE_MAX_HP_RATE)
                + ($actor->hp * self::SELF_DAMAGE_CURRENT_HP_RATE),
        );
        if ($requestedSelfDamage <= 0) {
            return;
        }

        $hpBefore = $actor->hp;
        $actor->takeDamage($requestedSelfDamage);
        $this->recordHpLoss($state, $actor, max(0, $hpBefore - $actor->hp));
    }

    private function recordHpLoss(
        BattleState $state,
        BattleActor $actor,
        int $actualHpLoss,
    ): void {
        if ($actualHpLoss <= 0) {
            return;
        }

        $combatantState = $this->combatantState($state, $actor);
        $combatantState->cumulativeHpLoss += $actualHpLoss;
        if ($combatantState->baselineMaxHp <= 0) {
            return;
        }

        for ($nextStack = $combatantState->stack + 1; $nextStack <= self::MAX_STACK; $nextStack++) {
            if (($combatantState->cumulativeHpLoss * 100)
                < ($combatantState->baselineMaxHp * self::STACK_THRESHOLD_PERCENT * $nextStack)
            ) {
                break;
            }

            $combatantState->stack = $nextStack;
        }
    }

    private function combatantState(
        BattleState $state,
        BattleActor $actor,
    ): BurningLifeCombatantState {
        if (! isset($this->battleStates[$state])) {
            $this->onBattleStart($state->player, $state->enemy, $state);
        }

        $actorKey = $state->actorKey($actor);
        $combatants = $this->battleStates[$state];
        if (! isset($combatants[$actorKey])) {
            $combatants[$actorKey] = new BurningLifeCombatantState($actor->maxHp);
            $this->battleStates[$state] = $combatants;
        }

        return $combatants[$actorKey];
    }

    private function scaleStat(int $value, float $multiplier): int
    {
        return max(0, (int) floor(($value * $multiplier) + 1.0e-9));
    }
}

final class BurningLifeCombatantState
{
    public function __construct(
        public readonly int $baselineMaxHp,
        public int $cumulativeHpLoss = 0,
        public int $stack = 0,
    ) {}
}
