<?php

namespace App\Services\Nation\Raid;

use InvalidArgumentException;

final readonly class NationRaidBattleInput
{
    public function __construct(
        public int $stage,
        public int $cycleCurrentHp,
        public int $cycleMaxHp,
        public string $sourceCycleId,
        public ?string $dominantLineage,
        public int $seed,
        public string $strategy,
        public NationRaidPlayerSnapshot $player,
    ) {
        if ($stage < 1 || $stage > NationRaidRules::MAX_STAGES) {
            throw new InvalidArgumentException('Raid stage must be between 1 and 20.');
        }
        if ($cycleMaxHp < 1 || $cycleCurrentHp < 1 || $cycleCurrentHp > $cycleMaxHp) {
            throw new InvalidArgumentException('Raid cycle HP snapshot is invalid.');
        }
        if (trim($sourceCycleId) === '') {
            throw new InvalidArgumentException('Raid source cycle ID must not be empty.');
        }
        if (! in_array($strategy, [
            NationRaidRules::STRATEGY_BOSS_SET,
            NationRaidRules::STRATEGY_ASSAULT,
            NationRaidRules::STRATEGY_INTERCEPT,
            NationRaidRules::STRATEGY_FORTIFY,
        ], true)) {
            throw new InvalidArgumentException('Unknown raid strategy.');
        }
    }
}
