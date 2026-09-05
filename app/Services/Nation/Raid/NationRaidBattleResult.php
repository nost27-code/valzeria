<?php

namespace App\Services\Nation\Raid;

final readonly class NationRaidBattleResult
{
    /**
     * @param  list<array<string, mixed>>  $turns
     * @param  list<array<string, mixed>>  $spTrace
     * @param  list<string>  $ultimateDenialReasons
     * @param  list<array<string, mixed>>  $preparationHistory
     * @param  list<?string>  $bossSetExactIdentities
     */
    public function __construct(
        public string $battleType,
        public int $stage,
        public string $form,
        public string $bossSpeciesKey,
        public int $seed,
        public string $rulesetHash,
        public string $strategy,
        public array $bossSetExactIdentities,
        public int $turnsCompleted,
        public string $outcome,
        public int $playerRemainingHp,
        public int $bossVirtualRemainingHp,
        public int $calculatedBossDamage,
        public int $maxOneActionDamage,
        public int $t20StartingSp,
        public array $turns,
        public array $spTrace,
        public array $ultimateDenialReasons,
        public int $reservationFailureCount,
        public array $preparationHistory,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
