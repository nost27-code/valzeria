<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Optional discovery batching preserves sequential battles and immediate reward/audit writes. */
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
            $locked = Character::whereKey($character->id)->lockForUpdate()->firstOrFail();
            request()->attributes->set('exploration_processing_mode', 'batch_reads');
            CharacterStatusService::clearRequestCache();
            try {
                $run = fn () => config('exploration_performance.batch_discoveries_enabled', false)
                    ? $this->runWithBatchedDiscoveries($character, $operation) : $operation();

                return app(ExplorationBatchReadContext::class)->withLockedCharacter($character, function () use ($character, $locked, $run): array {
                    if (config('exploration_performance.batch_state_enabled', false)
                        && config('exploration_performance.batch_discoveries_enabled', false)
                        && $this->hasCommittedHttpEnvelope()) {
                        $result = app(ExplorationBatchStateContext::class)->withLockedCharacter($character, $locked, $run);
                        request()->attributes->set('exploration_processing_mode', 'batch_state');

                        return $result;
                    }

                    return $run();
                });
            } finally {
                CharacterStatusService::clearRequestCache();
            }
        });
    }

    private function hasCommittedHttpEnvelope(): bool
    {
        // The commit middleware locks the owner before its first snapshot read.
        // Direct callers can already have an older REPEATABLE READ snapshot.
        $token = request()->attributes->get('committed_exploration_token');

        return request()->isMethod('POST') && is_string($token) && Str::isUuid($token)
            && DB::transactionLevel() >= 2;
    }

    private function runWithBatchedDiscoveries(Character $character, callable $operation): array
    {
        request()->attributes->set('exploration_processing_mode', app(ExplorationBatchStateContext::class)->activeFor($character)
            ? 'batch_state' : 'batch_discoveries');

        return app(ExplorationBatchWriteContext::class)->withLockedCharacter($character, $operation);
    }
}
