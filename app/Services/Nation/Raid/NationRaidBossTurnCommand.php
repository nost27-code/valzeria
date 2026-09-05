<?php

namespace App\Services\Nation\Raid;

/** @internal NationRaidBossTurnSessionとengine generator間のprotocol。 */
final readonly class NationRaidBossTurnCommand
{
    public function __construct(
        public NationRaidPlayerActionSnapshot $playerAction,
        public ?NationRaidPlayerTurnState $livePlayerState = null,
    ) {}
}
