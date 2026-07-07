<?php

namespace App\Services;

use App\Models\Character;
use App\Models\TowerCharacterRecord;
use App\Models\TowerRun;
use App\Models\TowerWeeklyRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class TowerRankingService
{
    public function weeklyRanking(string $towerKey, string $seasonKey, int $limit = 100)
    {
        return TowerWeeklyRecord::query()
            ->with(['character.jobClass'])
            ->where('tower_key', $towerKey)
            ->where('season_key', $seasonKey)
            ->where('best_cleared_floor', '>', 0)
            ->orderByDesc('best_cleared_floor')
            ->orderBy('achieved_at')
            ->orderBy('best_run_id')
            ->limit($limit)
            ->get();
    }

    public function allTimeRanking(string $towerKey, int $limit = 100)
    {
        return TowerCharacterRecord::query()
            ->with(['character.jobClass'])
            ->where('tower_key', $towerKey)
            ->where('best_cleared_floor', '>', 0)
            ->orderByDesc('best_cleared_floor')
            ->orderBy('achieved_at')
            ->orderBy('best_run_id')
            ->limit($limit)
            ->get();
    }

    public function recordRunStarted(Character $character, string $towerKey): TowerCharacterRecord
    {
        $record = TowerCharacterRecord::query()->firstOrCreate(
            [
                'character_id' => $character->id,
                'tower_key' => $towerKey,
            ],
            [
                'best_cleared_floor' => 0,
                'total_runs' => 0,
                'total_wins' => 0,
                'total_defeats' => 0,
                'total_returns' => 0,
            ]
        );

        $record->increment('total_runs');

        return $record->refresh();
    }

    public function recordFloorCleared(TowerRun $run, ?CarbonInterface $achievedAt = null): void
    {
        $record = $this->allTimeRecord($run);
        $record->increment('total_wins');

        $this->updateBestRecords($run, $achievedAt);
    }

    public function recordRunDefeated(TowerRun $run, ?CarbonInterface $achievedAt = null): void
    {
        $record = $this->allTimeRecord($run);
        $record->increment('total_defeats');

        $this->updateBestRecords($run, $achievedAt);
    }

    public function recordRunReturned(TowerRun $run, ?CarbonInterface $achievedAt = null): void
    {
        $record = $this->allTimeRecord($run);
        $record->increment('total_returns');

        $this->updateBestRecords($run, $achievedAt);
    }

    public function updateBestRecords(TowerRun $run, ?CarbonInterface $achievedAt = null): void
    {
        $achievedAt ??= now();

        $this->updateWeeklyBest($run, $achievedAt);
        $this->updateAllTimeBest($run, $achievedAt);
    }

    private function updateWeeklyBest(TowerRun $run, CarbonInterface $achievedAt): TowerWeeklyRecord
    {
        $record = TowerWeeklyRecord::query()->firstOrCreate(
            [
                'character_id' => $run->character_id,
                'tower_key' => $run->tower_key,
                'season_key' => $run->season_key,
            ],
            [
                'best_cleared_floor' => 0,
            ]
        );

        $oldBest = (int) ($record->best_cleared_floor ?? 0);
        if ($this->shouldReplaceBest($oldBest, $record->best_failed_floor, $run)) {
            $record->forceFill($this->bestPayload($run, $achievedAt))->save();
            $this->publishMilestoneLog($run, $oldBest);
        }

        return $record;
    }

    private function updateAllTimeBest(TowerRun $run, CarbonInterface $achievedAt): TowerCharacterRecord
    {
        $record = $this->allTimeRecord($run);

        if ($this->shouldReplaceBest($record->best_cleared_floor, $record->best_failed_floor, $run)) {
            $record->forceFill($this->bestPayload($run, $achievedAt))->save();
        }

        return $record;
    }

    private function allTimeRecord(TowerRun $run): TowerCharacterRecord
    {
        return TowerCharacterRecord::query()->firstOrCreate(
            [
                'character_id' => $run->character_id,
                'tower_key' => $run->tower_key,
            ],
            [
                'best_cleared_floor' => 0,
                'total_runs' => 0,
                'total_wins' => 0,
                'total_defeats' => 0,
                'total_returns' => 0,
            ]
        );
    }

    private function shouldReplaceBest(?int $currentCleared, ?int $currentFailed, TowerRun $run): bool
    {
        $newCleared = (int) $run->cleared_floor;
        $oldCleared = (int) ($currentCleared ?? 0);

        if ($newCleared > $oldCleared) {
            return true;
        }

        if ($newCleared < $oldCleared) {
            return false;
        }

        return (int) ($run->failed_floor ?? 0) > (int) ($currentFailed ?? 0);
    }

    /**
     * @return array{best_cleared_floor:int,best_failed_floor:?int,best_run_id:int,achieved_at:CarbonInterface}
     */
    private function bestPayload(TowerRun $run, CarbonInterface $achievedAt): array
    {
        return [
            'best_cleared_floor' => (int) $run->cleared_floor,
            'best_failed_floor' => $run->failed_floor,
            'best_run_id' => $run->id,
            'achieved_at' => $achievedAt,
        ];
    }

    private function publishMilestoneLog(TowerRun $run, int $oldBest): void
    {
        $newBest = (int) $run->cleared_floor;
        $milestone = max(10, intdiv($newBest, 10) * 10);
        if ($newBest < 10 || $milestone <= $oldBest || !Schema::hasTable('public_logs')) {
            return;
        }

        $character = Character::query()->find((int) $run->character_id);
        if (!$character) {
            return;
        }

        $logLabel = (string) config('star_tree_tower.star_tree.display.public_log_label', '星梯の塔');
        $towerName = (string) config('star_tree_tower.star_tree.display.name', '星樹の塔');

        app(PublicLogService::class)->addLog(
            'tower',
            "【{$logLabel}】{$character->name}さんが{$towerName} {$milestone}階を踏破しました！",
            $character,
            $milestone >= 50 ? 3 : 2
        );
    }
}
