<?php

namespace App\Services\Nation\Raid;

final readonly class NationRaidCounterplayContext
{
    public function __construct(
        public bool $hit,
        public bool $canBeGuarded,
        public int $bossSp,
        public int $bossMaxSp = NationRaidRules::BOSS_MAX_SP,
        public int $huntingMarkCount = 0,
        public int $breakMarkCount = 0,
        public ?NationRaidTelegraphPreparationState $preparation = null,
        public bool $alreadyDelayed = false,
    ) {}
}
