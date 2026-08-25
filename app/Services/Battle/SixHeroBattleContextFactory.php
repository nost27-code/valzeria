<?php

namespace App\Services\Battle;

use App\Enums\SixHeroRoomKey;
use App\Support\SixHeroCompetitionRules;

final class SixHeroBattleContextFactory
{
    public function __construct(
        private readonly SixHeroRoomRuleResolver $roomRuleResolver,
    ) {}

    public function makeOfficial(SixHeroRoomKey $room): PvPBattleExecutionContext
    {
        return $this->make($room);
    }

    public function makePractice(SixHeroRoomKey $room): PvPBattleExecutionContext
    {
        return $this->make($room);
    }

    public function make(SixHeroRoomKey $room): PvPBattleExecutionContext
    {
        return new PvPBattleExecutionContext(
            displayLabel: $room->label(),
            jobArtContext: 'champ',
            roomRule: $this->roomRuleResolver->resolve($room),
            rankBattleMinimumDamageGuaranteeEnabled: false,
            rankBattleDamageCapEnabled: false,
            rankBattleBaseDamageMultiplier: SixHeroCompetitionRules::BASE_DAMAGE_MULTIPLIER,
            rankBattleNormalAttackPower: SixHeroCompetitionRules::NORMAL_ATTACK_POWER,
            speedBreakthroughEnabled: true,
        );
    }
}
