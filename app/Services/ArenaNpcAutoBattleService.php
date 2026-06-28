<?php

namespace App\Services;

use App\Models\ArenaNpcAutoLog;
use App\Models\ArenaNpcRanking;
use App\Models\ArenaRanking;
use App\Models\Character;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArenaNpcAutoBattleService
{
    public function __construct(
        private readonly ArenaNpcRankingService $rankingService,
        private readonly CharacterNotificationService $notificationService
    ) {
    }

    public function runScheduled(int $battleCount = 1): array
    {
        if (! Schema::hasTable('arena_npc_rankings') || ! Schema::hasTable('arena_npc_auto_logs')) {
            return ['attempted' => 0, 'completed' => 0, 'reason' => 'missing_tables'];
        }

        $battleCount = max(1, min(3, $battleCount));
        $completed = 0;

        for ($i = 0; $i < $battleCount; $i++) {
            if ($this->runOneBattle()) {
                $completed++;
            }
        }

        return [
            'attempted' => $battleCount,
            'completed' => $completed,
        ];
    }

    public function runOneBattle(): ?ArenaNpcAutoLog
    {
        return DB::transaction(function (): ?ArenaNpcAutoLog {
            $attacker = ArenaNpcRanking::with('npc')
                ->where('is_active', true)
                ->where('rank', '>', ArenaNpcRankingService::PLAYER_TOP_PROTECTED_RANK + 1)
                ->inRandomOrder()
                ->lockForUpdate()
                ->first();

            if (! $attacker) {
                return null;
            }

            $target = $this->targetFor($attacker);
            if (! $target) {
                return null;
            }

            $attackerOldRank = (int) $attacker->rank;
            $defenderOldRank = (int) $target['rank'];
            $isAttackerWin = $this->decideWinner($attacker, $target);
            $attackerNewRank = $attackerOldRank;
            $defenderNewRank = $defenderOldRank;

            if ($isAttackerWin) {
                $this->rankingService->recordNpcWin($attacker);

                if ($target['type'] === 'player') {
                    /** @var ArenaRanking $defenderRanking */
                    $defenderRanking = $target['ranking'];
                    $defenderRanking->losses++;
                    $defenderRanking->save();
                } else {
                    /** @var ArenaNpcRanking $defenderRanking */
                    $defenderRanking = $target['ranking'];
                    $defenderRanking->losses++;
                    $defenderRanking->save();
                }

                $temporaryRank = -100000 - (int) $attacker->id;
                $attacker->rank = $temporaryRank;
                $attacker->save();

                $this->rankingService->shiftCombinedRanksDown(
                    $defenderOldRank,
                    $attackerOldRank - 1,
                    null,
                    (int) $attacker->id
                );

                $attacker->rank = $defenderOldRank;
                $attackerNewRank = $defenderOldRank;

                $defenderRanking->refresh();
                $defenderNewRank = (int) $defenderRanking->rank;
            } else {
                $attacker->losses++;

                if ($target['type'] === 'player') {
                    /** @var ArenaRanking $defenderRanking */
                    $defenderRanking = $target['ranking'];
                    $defenderRanking->wins++;
                    $defenderRanking->save();
                } else {
                    /** @var ArenaNpcRanking $defenderRanking */
                    $defenderRanking = $target['ranking'];
                    $this->rankingService->recordNpcWin($defenderRanking);
                    $defenderRanking->save();
                }
            }

            $attacker->save();

            $log = ArenaNpcAutoLog::create([
                'attacker_npc_ranking_id' => (int) $attacker->id,
                'attacker_npc_id' => (int) $attacker->npc_id,
                'defender_type' => $target['type'],
                'defender_character_id' => $target['type'] === 'player' ? (int) $target['character_id'] : null,
                'defender_npc_ranking_id' => $target['type'] === 'npc' ? (int) $target['ranking']->id : null,
                'defender_npc_id' => $target['type'] === 'npc' ? (int) $target['ranking']->npc_id : null,
                'is_attacker_win' => $isAttackerWin,
                'attacker_old_rank' => $attackerOldRank,
                'attacker_new_rank' => $attackerNewRank,
                'defender_old_rank' => $defenderOldRank,
                'defender_new_rank' => $defenderNewRank,
            ]);

            if ($target['type'] === 'player' && $isAttackerWin && $defenderNewRank !== $defenderOldRank) {
                $this->notifyRankDown($target['character'], $attacker, $defenderOldRank, $defenderNewRank);
            }

            return $log;
        });
    }

    private function targetFor(ArenaNpcRanking $attacker): ?array
    {
        $players = ArenaRanking::with('character')
            ->where('rank', '>=', ArenaNpcRankingService::PLAYER_TOP_PROTECTED_RANK + 1)
            ->where('rank', '<', (int) $attacker->rank)
            ->lockForUpdate()
            ->get()
            ->filter(fn (ArenaRanking $ranking): bool => $ranking->character !== null)
            ->map(fn (ArenaRanking $ranking): array => [
                'type' => 'player',
                'rank' => (int) $ranking->rank,
                'level' => (int) ($ranking->character?->level ?? 1),
                'ranking' => $ranking,
                'character' => $ranking->character,
                'character_id' => (int) $ranking->character_id,
            ]);

        $npcs = ArenaNpcRanking::with('npc')
            ->where('is_active', true)
            ->where('id', '!=', $attacker->id)
            ->where('rank', '>=', ArenaNpcRankingService::PLAYER_TOP_PROTECTED_RANK + 1)
            ->where('rank', '<', (int) $attacker->rank)
            ->lockForUpdate()
            ->get()
            ->map(fn (ArenaNpcRanking $ranking): array => [
                'type' => 'npc',
                'rank' => (int) $ranking->rank,
                'level' => (int) $ranking->level,
                'ranking' => $ranking,
            ]);

        $targets = $players
            ->concat($npcs)
            ->sortByDesc('rank')
            ->take(3)
            ->values();
        if ($targets->isEmpty()) {
            return null;
        }

        return $targets->random();
    }

    private function decideWinner(ArenaNpcRanking $attacker, array $target): bool
    {
        $levelDiff = (int) $attacker->level - (int) $target['level'];
        $rankGap = max(1, (int) $attacker->rank - (int) $target['rank']);
        $chance = 43 + (int) floor($levelDiff / 2) + min(6, $rankGap);
        $chance = max(30, min(65, $chance));

        return random_int(1, 100) <= $chance;
    }

    private function notifyRankDown(Character $character, ArenaNpcRanking $attacker, int $oldRank, int $newRank): void
    {
        $attacker->loadMissing('npc');
        $attackerName = $this->rankingService->npcDisplayName($attacker->npc);

        $this->notificationService->create(
            $character,
            'arena',
            'arena_rank_down',
            'ランク戦順位が低下しました',
            "{$attackerName}さんの勝利により、闘技場順位が{$oldRank}位から{$newRank}位に下がりました。",
            '順位を見る',
            route('colosseum.ranking'),
            [
                'attacker_npc_ranking_id' => (int) $attacker->id,
                'old_rank' => $oldRank,
                'new_rank' => $newRank,
            ],
            75
        );
    }
}
