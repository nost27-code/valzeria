<?php

namespace App\Services;

use App\Models\BattleLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NamelessBattleHistoryService
{
    public function scoresForPeriod(array $period): array
    {
        $scores = [];
        foreach ($this->operations(null, $period) as $operation) {
            $wins = $this->summaryForRow($operation)['wins'];
            if ($wins > 0) {
                $id = (int) $operation->character_id;
                $scores[$id] = ($scores[$id] ?? 0) + $wins;
            }
        }
        return $scores;
    }

    /** @return array{wins:int,losses:int,boss_wins:int} */
    public function totalsForCharacter(int $characterId): array
    {
        $total = ['wins' => 0, 'losses' => 0, 'boss_wins' => 0];
        foreach ($this->operations($characterId) as $operation) {
            foreach ($this->summaryForRow($operation) as $key => $value) {
                $total[$key] += $value;
            }
        }
        return $total;
    }

    /** @return array{wins:int,losses:int,boss_wins:int} */
    public function summarize(array $snapshot): array
    {
        $total = ['wins' => 0, 'losses' => 0, 'boss_wins' => 0];
        $runs = data_get($snapshot, 'batch_explore.runs');
        if (is_array($runs)) {
            $bossIndexes = [];
            $encounters = $snapshot['rare_encounters'] ?? [];
            foreach (is_array($encounters) ? $encounters : [] as $encounter) {
                if (is_array($encounter) && ($encounter['kind'] ?? null) === 'cleared_boss' && (int) ($encounter['index'] ?? 0) > 0) {
                    $bossIndexes[(int) ($encounter['index'] ?? 0)] = true;
                }
            }
            foreach ($runs as $position => $run) {
                if (! is_array($run) || (int) ($run['turn_count'] ?? 0) <= 0) {
                    continue;
                }
                $index = (int) ($run['index'] ?? ((int) $position + 1));
                if (in_array($run['result'] ?? null, BattleLog::WIN_RESULTS, true)) {
                    $total['wins']++;
                    $total['boss_wins'] += (int) isset($bossIndexes[$index]);
                } elseif (in_array($run['result'] ?? null, BattleLog::LOSS_RESULTS, true)) {
                    $total['losses']++;
                }
            }
            return $total;
        }
        if ((int) ($snapshot['turn_count'] ?? 0) <= 0) {
            return $total;
        }
        if (in_array($snapshot['result'] ?? null, BattleLog::WIN_RESULTS, true)) {
            $total['wins'] = 1;
            $boss = in_array($snapshot['boss'] ?? false, [true, 1, 'true', '1'], true)
                || ($snapshot['encounter_kind'] ?? null) === 'cleared_boss';
            $total['boss_wins'] = (int) $boss;
        } elseif (in_array($snapshot['result'] ?? null, BattleLog::LOSS_RESULTS, true)) {
            $total['losses'] = 1;
        }
        return $total;
    }

    private function operations(?int $characterId = null, ?array $period = null): iterable
    {
        // Historical results remain valid after switching the relic feature OFF.
        if (! Schema::hasColumns('nameless_workshop_operations', ['id', 'character_id', 'action', 'result', 'created_at'])) {
            return [];
        }
        return DB::table('nameless_workshop_operations')->where('action', 'ruin')
            ->when($characterId !== null, fn ($query) => $query->where('character_id', $characterId))
            ->when($period !== null, fn ($query) => $query->where('created_at', '>=', $period['start_at'])->where('created_at', '<', $period['end_at']))
            ->select(['id', 'character_id', 'result->result as battle_result', 'result->turn_count as turns',
                'result->boss as boss', 'result->encounter_kind as encounter_kind',
                'result->batch_explore->runs as runs', 'result->rare_encounters as rare_encounters'])
            ->lazyById(200);
    }

    private function summaryForRow(object $operation): array
    {
        $runs = json_decode($operation->runs ?? 'null', true);
        return $this->summarize([
            'result' => $operation->battle_result, 'turn_count' => $operation->turns,
            'boss' => $operation->boss, 'encounter_kind' => $operation->encounter_kind,
            'batch_explore' => is_array($runs) ? ['runs' => $runs] : null,
            'rare_encounters' => json_decode($operation->rare_encounters ?? 'null', true) ?? [],
        ]);
    }
}
