<?php

namespace App\Services\Admin;

use App\Enums\SixHeroRoomKey;
use App\Models\Character;
use App\Services\Battle\SixHeroBattleContextFactory;
use App\Services\CharacterStatusService;
use App\Services\PvPBattleService;
use App\Services\SixHeroBattleResultPresenter;
use App\Services\SixHeroPracticeBattleResult;
use DomainException;
use Illuminate\Support\Facades\DB;

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
        bool $recordReplay = false,
    ): SixHeroPracticeBattleResult {
        $attackerId = (int) $attacker->getKey();
        $defenderId = (int) $defender->getKey();
        if ($attackerId <= 0 || $defenderId <= 0) {
            throw new DomainException('Both characters must already exist.');
        }
        if ($attackerId === $defenderId) {
            throw new DomainException('A character cannot battle itself.');
        }

        // Keep even optional telemetry and future side effects out of game data.
        DB::beginTransaction();
        try {
            return new SixHeroPracticeBattleResult(
                resolution: $this->pvpBattleService->resolveBattle(
                    $attacker,
                    $defender,
                    $this->battleContextFactory->makePractice($room, recordReplay: $recordReplay),
                ),
                room: $room,
            );
        } finally {
            DB::rollBack();
        }
    }

    /** Capture the current DB state and one actual resolution, without registering a Season. */
    public function preview(SixHeroRoomKey $room, int $attackerId, int $defenderId): array
    {
        DB::beginTransaction();
        try {
            $characters = Character::query()->with(['currentJob', 'jobHistories', 'jobArtSlots.skill'])
                ->whereKey([$attackerId, $defenderId])->get()->keyBy('id');
            $attacker = $characters->get($attackerId);
            $defender = $characters->get($defenderId);
            if (! $attacker || ! $defender) {
                throw new DomainException('Selected character no longer exists.');
            }
            CharacterStatusService::clearRequestCache($attackerId);
            CharacterStatusService::clearRequestCache($defenderId);
            $result = $this->simulate($room, $attacker, $defender, recordReplay: true);
            $summary = app(SixHeroBattleResultPresenter::class)->simulation($room, $attacker, $defender, $result);

            return [
                'battleResult' => $summary,
                'battleLogs' => $summary['logs'],
                'battleReplay' => $result->resolution->result->battleReplay,
                'capturedAt' => now()->format('Y-m-d H:i:s T'),
                'environment' => app()->environment(),
            ];
        } finally {
            DB::rollBack();
        }
    }
}
