<?php

namespace App\Services;

use App\Models\Character;
use App\Models\ExplorationMap;
use App\Models\TownMapRegistration;
use Illuminate\Support\Facades\DB;

class ExplorationMapDiscardService
{
    public function discard(Character $character, ExplorationMap $map): void
    {
        $this->discardMany($character, [$map->id]);
    }

    /** @param array<int, int|string> $mapIds */
    public function discardMany(Character $character, array $mapIds): int
    {
        $ids = collect($mapIds)
            ->map(fn (int|string $mapId): int => (int) $mapId)
            ->filter(fn (int $mapId): bool => $mapId > 0)
            ->unique()
            ->sort()
            ->values();

        if ($ids->isEmpty()) {
            throw new \RuntimeException('破棄する地図を選んでください。');
        }

        return DB::transaction(function () use ($character, $ids): int {
            $maps = ExplorationMap::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($maps->count() !== $ids->count()
                || $maps->contains(fn (ExplorationMap $map): bool => $map->owner_character_id !== $character->id)) {
                throw new \RuntimeException('この地図は破棄できません。');
            }
            if ($maps->contains(fn (ExplorationMap $map): bool => !in_array($map->status, ['uninvestigated', 'surveyed'], true))) {
                throw new \RuntimeException('公開中または処理中の地図は破棄できません。');
            }

            TownMapRegistration::query()
                ->whereIn('map_id', $ids)
                ->lockForUpdate()
                ->update(['survey_status' => 'discarded', 'status' => 'discarded']);
            ExplorationMap::query()
                ->whereIn('id', $ids)
                ->update(['status' => 'discarded']);

            return $maps->count();
        });
    }
}
