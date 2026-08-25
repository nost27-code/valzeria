<?php

namespace App\Services\Admin;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Services\Battle\SixHeroBattleContextFactory;
use App\Services\PvPBattleService;
use App\Services\SixHeroPracticeBattleResult;
use DomainException;

final class SixHeroBattleSimulatorService
{
    public function __construct(
        private readonly PvPBattleService $pvpBattleService,
        private readonly SixHeroBattleContextFactory $battleContextFactory,
    ) {}

    /**
     * 六英雄公式戦と同じRoomRule・damage方針で、永続化しない検証戦を解決する。
     */
    public function simulate(
        SixHeroRoomKey $room,
        Character $attacker,
        Character $defender,
    ): SixHeroPracticeBattleResult {
        $attackerId = (int) $attacker->getKey();
        $defenderId = (int) $defender->getKey();
        if ($attackerId <= 0 || $defenderId <= 0) {
            throw new DomainException('Both characters must already exist.');
        }
        if ($attackerId === $defenderId) {
            throw new DomainException('A character cannot battle itself.');
        }

        return new SixHeroPracticeBattleResult(
            resolution: $this->pvpBattleService->resolveBattle(
                $attacker,
                $defender,
                $this->battleContextFactory->make($room),
            ),
            room: $room,
        );
    }
}
