<?php

namespace App\Livewire;

use App\Services\ArenaNpcRankingService;
use App\Services\StarTreeTowerService;
use App\Services\TowerRankingService;
use Livewire\Component;

class StarTreeTowerRankingWidget extends Component
{
    private const DISPLAY_LIMIT = 5;

    public function render(
        StarTreeTowerService $towerService,
        TowerRankingService $rankingService,
        ArenaNpcRankingService $arenaRankingService,
    ) {
        $towerEnabled = $towerService->isEnabled();
        $towerRecords = $towerEnabled
            ? $rankingService->allTimeRanking($towerService->towerKey(), self::DISPLAY_LIMIT)
            : collect();

        $arenaEntries = $arenaRankingService->rankingEntries(self::DISPLAY_LIMIT);

        return view('livewire.star-tree-tower-ranking-widget', [
            'towerEnabled' => $towerEnabled,
            'towerRecords' => $towerRecords,
            'arenaEntries' => $arenaEntries,
        ]);
    }
}
