<?php

namespace App\Services\Nation\Raid\Simulation;

use App\Services\Nation\Raid\NationRaidBattleResult;

/** turn-by-turn bridgeの検証可能な出力。DBへは保存しない。 */
final readonly class NationRaidTurnByTurnBridgeResult
{
    /**
     * @param  list<array<string, mixed>>  $actions
     * @param  array<int, int>  $selectionCallsByTurn
     * @param  list<int>  $selectionOrder
     * @param  array<int, bool>  $telegraphClosedAfterSelection
     * @param  array<string, mixed>  $bossIsolation
     * @param  list<string>  $knownGaps
     * @param  list<string>  $playerBattleLogs
     * @param  list<array<string, mixed>>  $playerTurnMetrics
     */
    public function __construct(
        public NationRaidBattleResult $battleResult,
        public array $actions,
        public array $selectionCallsByTurn,
        public array $selectionOrder,
        public array $telegraphClosedAfterSelection,
        public array $bossIsolation,
        public array $knownGaps,
        public array $playerBattleLogs,
        public array $playerTurnMetrics = [],
    ) {}

    /** @return array{profile_no:int,actions:list<array<string,mixed>>} */
    public function profile(int $profileNo): array
    {
        return [
            'profile_no' => $profileNo,
            'actions' => $this->actions,
        ];
    }
}
