<?php

namespace App\Livewire;

use App\Models\Character;
use App\Services\ArenaNpcRankingService;
use App\Services\StarTreeTowerService;
use App\Services\TowerRankingService;
use App\Services\WeeklyWinRankingService;
use Livewire\Component;

class StarTreeTowerRankingWidget extends Component
{
    private const DISPLAY_LIMIT = 5;

    public array $arenaEntries = [];

    public bool $arenaEntriesLoaded = false;

    public function loadArenaEntries(ArenaNpcRankingService $arenaRankingService): void
    {
        if ($this->arenaEntriesLoaded) {
            return;
        }

        $this->arenaEntries = $arenaRankingService
            ->rankingEntries(self::DISPLAY_LIMIT)
            ->values()
            ->all();
        $this->arenaEntriesLoaded = true;
    }

    /**
     * デプロイ前から開かれている画面の旧wire:clickを安全に受ける互換処理。
     * 新規表示ではBladeからAdventurerCardModalへ直接dispatchする。
     */
    public function openWeeklyWinPlayerModal(int $characterId): void
    {
        $character = Character::visibleToPublic()->find($characterId);

        if (! $character) {
            return;
        }

        $this->dispatch('open-adventurer-card', characterId: (int) $character->id)
            ->to(component: AdventurerCardModal::class);
    }

    public function render(
        StarTreeTowerService $towerService,
        TowerRankingService $rankingService,
        WeeklyWinRankingService $weeklyWinRankingService,
    ) {
        $towerEnabled = $towerService->isEnabled();
        $towerRecords = $towerEnabled
            ? $rankingService->allTimeRanking($towerService->towerKey(), self::DISPLAY_LIMIT)
            : collect();

        $weeklyWinData = $weeklyWinRankingService->currentWidgetData(
            auth()->user()?->currentCharacter(),
            self::DISPLAY_LIMIT
        );

        return view('livewire.star-tree-tower-ranking-widget', [
            'towerEnabled' => $towerEnabled,
            'towerRecords' => $towerRecords,
            'arenaEntries' => collect($this->arenaEntries),
            'weeklyWinData' => $weeklyWinData,
        ]);
    }

    public function placeholder()
    {
        return view('livewire.ranking-widget-placeholder');
    }
}
