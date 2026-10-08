<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use Illuminate\Support\Facades\DB;

/** First rollout stage: share unchanged reads, preserving every individual write. */
class NormalExplorationBatchService
{
    public function run(Character $character, int $areaId, callable $operation): array
    {
        // Initial rollout targets the measured NAM-on workload; NAM-off timing needs further evaluation.
        if (! config('exploration_performance.batch_reads_enabled', false)
            || ! config('nameless_relics.enabled', false)) {
            return $operation();
        }
        $area = Area::find($areaId);
        if (! $area || app(RegionDepthDungeonService::class)->isRegionDepthArea($area)) {
            return $operation();
        }

        return DB::transaction(function () use ($character, $operation): array {
            // Also protect direct service callers; a transaction alone is not an ownership lock.
            Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            request()->attributes->set('exploration_processing_mode', 'batch_reads');
            CharacterStatusService::clearRequestCache();
            try {
                return app(ExplorationBatchReadContext::class)->withLockedCharacter($character, $operation);
            } finally {
                CharacterStatusService::clearRequestCache();
            }
        });
    }
}
