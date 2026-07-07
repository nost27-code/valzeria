<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Title;
use App\Models\TowerCharacterRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class TowerTitleRewardService
{
    public function __construct(
        private readonly TitleService $titleService,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function unlockFloorMilestones(Character $character, int $clearedFloor): array
    {
        if ($clearedFloor < 10 || ! Schema::hasTable('titles') || ! Schema::hasTable('character_titles')) {
            return [];
        }

        $titles = $this->candidateTitles($clearedFloor);
        if ($titles->isEmpty()) {
            return [];
        }

        $ownedTitleIds = $character->titles()
            ->whereIn('title_id', $titles->pluck('id')->all())
            ->pluck('title_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $owned = array_flip($ownedTitleIds);
        $unlockedNames = [];

        foreach ($titles as $title) {
            if (isset($owned[(int) $title->id])) {
                continue;
            }

            $this->titleService->unlockTitle($character, (int) $title->id);
            $unlockedNames[] = (string) $title->name;
        }

        return $unlockedNames;
    }

    /**
     * @return array<int, string>
     */
    public function unlockEarnedMilestones(Character $character, string $towerKey): array
    {
        if (! Schema::hasTable('tower_character_records')) {
            return [];
        }

        $bestClearedFloor = (int) (TowerCharacterRecord::query()
            ->where('character_id', $character->id)
            ->where('tower_key', $towerKey)
            ->value('best_cleared_floor') ?? 0);

        return $this->unlockFloorMilestones($character, $bestClearedFloor);
    }

    /**
     * @return Collection<int, Title>
     */
    private function candidateTitles(int $clearedFloor): Collection
    {
        return Title::query()
            ->where('unlock_type', 'tower_floor_clear')
            ->where('target_type', 'tower_floor')
            ->get()
            ->filter(fn (Title $title) => (int) $title->target_id <= $clearedFloor)
            ->sortBy(fn (Title $title) => (int) $title->target_id)
            ->values();
    }
}
