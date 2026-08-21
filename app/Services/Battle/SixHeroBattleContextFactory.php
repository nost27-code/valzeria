<?php

namespace App\Services\Battle;

use App\Enums\SixHeroRoomKey;

final class SixHeroBattleContextFactory
{
    public function __construct(
        private readonly SixHeroRoomRuleResolver $roomRuleResolver,
    ) {}

    public function make(SixHeroRoomKey $room): PvPBattleExecutionContext
    {
        return new PvPBattleExecutionContext(
            displayLabel: $room->label(),
            jobArtContext: 'champ',
            roomRule: $this->roomRuleResolver->resolve($room),
            rankBattleMinimumDamageGuaranteeEnabled: false,
            rankBattleDamageCapEnabled: false,
        );
    }
}
