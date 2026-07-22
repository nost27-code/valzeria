<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\ExplorationMap;
use App\Models\MapExplorationBatch;
use App\Models\MapExplorationResult;
use App\Models\TownMapRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MapExplorationBatchService
{
    public function __construct(private readonly ExplorationMapSeedService $seeds, private readonly MapIncomeService $income, private readonly ExplorationMapDifficultyService $difficulty, private readonly MapExplorationRewardService $rewards) {}

    public function reserve(Character $character, TownMapRegistration $registration, int $requestedCount, string $requestUuid, bool $chargeEntryFee = true): MapExplorationBatch
    {
        $requestedCount = max(1, min(10, $requestedCount));
        return DB::transaction(function () use ($character, $registration, $requestedCount, $requestUuid, $chargeEntryFee) {
            if ($existing = MapExplorationBatch::where('request_uuid', $requestUuid)->first()) return $existing;
            $registration = TownMapRegistration::with('map')->lockForUpdate()->findOrFail($registration->id);
            if (!$registration->isOpen()) throw new \RuntimeException($registration->remaining_explorations <= 0 ? '他の冒険者による探索によって、この地図の探索可能回数は終了しました。今回の探索料金と探索力は消費されていません。' : 'この地図の公開期間は終了しました。');
            $reserved = min($requestedCount, (int) $registration->remaining_explorations);
            $entryFee = $character->id === $registration->map->owner_character_id ? 0 : (int) $registration->entry_fee_per_exploration;
            $total = $reserved > 0 && $chargeEntryFee ? $entryFee : 0;
            $lockedCharacter = Character::lockForUpdate()->findOrFail($character->id);
            if ($total > 0) app(GoldService::class)->spend($lockedCharacter, $total, 'map_entry_fee', '探索の地図の入場料', TownMapRegistration::class, $registration->id, ['map_id' => $registration->map_id, 'count' => $reserved, 'entry_count' => 1]);
            $first = $registration->consumed_explorations + 1;
            $registration->decrement('remaining_explorations', $reserved);
            $registration->increment('consumed_explorations', $reserved);
            return MapExplorationBatch::create(['uuid' => (string) Str::uuid(), 'request_uuid' => $requestUuid, 'registration_id' => $registration->id, 'map_id' => $registration->map_id, 'character_id' => $character->id, 'requested_count' => $requestedCount, 'reserved_count' => $reserved, 'first_exploration_index' => $first, 'last_exploration_index' => $first + $reserved - 1, 'fee_per_exploration' => $entryFee, 'total_fee' => $total, 'status' => 'reserved']);
        });
    }

    public function execute(Character $character, MapExplorationBatch $batch): array
    {
        $batch->loadMissing('map.sourceArea', 'registration');
        if ($batch->character_id !== $character->id) abort(403);
        if ($batch->status === 'completed') {
            return ['batch' => $batch->fresh(['map', 'registration.town']), 'battle_result' => $this->savedBattleResult($batch)];
        }
        $batch->update(['status' => 'processing', 'started_at' => $batch->started_at ?? now()]);
        $map = $batch->map;
        $root = $this->seeds->decrypt($map->seed_encrypted);
        $battleResult = null;
        $runResults = [];
        for ($index = $batch->first_exploration_index; $index <= $batch->last_exploration_index; $index++) {
            if (MapExplorationResult::where('map_id', $map->id)->where('global_exploration_index', $index)->exists()) continue;
            $character->refresh();
            if ($character->current_hp <= 0) break;
            $battleResult = $this->executeOne($character, $batch, $map, $root, $index);
            $runResults[] = $battleResult;
            $batch->increment('executed_count');
        }
        $batch->refresh();
        $unexecuted = $batch->reserved_count - $batch->executed_count;
        if ($unexecuted > 0) $this->refundUnexecuted($character, $batch, $unexecuted);
        $batch->refresh();
        $summary = $this->summary($batch);
        $batch->update(['status' => 'completed', 'completed_at' => now(), 'result_summary_json' => $summary]);
        $this->income->settle($batch->fresh());
        $completedBatch = $batch->fresh(['map', 'registration.town', 'results']);

        return [
            'batch' => $completedBatch,
            'battle_result' => $this->presentBattleResult($completedBatch, $battleResult ?? $this->savedBattleResult($completedBatch), $runResults),
        ];
    }

    private function executeOne(Character $character, MapExplorationBatch $batch, ExplorationMap $map, string $root, int $index): array
    {
        $encounterSeed = $this->seeds->explorationSeed($root, 'encounter', $index, $character->id);
        $rewardSeed = $this->seeds->explorationSeed($root, 'reward', $index, $character->id);
        $variants = $map->normal_monster_variants_json ?: [];
        if ($variants === []) throw new \RuntimeException('地図のモンスター候補がありません。');
        $variant = $variants[$this->seeds->int($encounterSeed, 'map:encounter:monster', 0, count($variants) - 1)];
        $enemy = Enemy::findOrFail($variant['base_monster_id']);
        $enemy = clone $enemy;
        $enemy->name = (string) $variant['display_name'];
        foreach (($variant['stat_modifiers'] ?? []) as $key => $percent) {
            $column = match ($key) { 'attack_percent' => 'str', 'defense_percent' => 'def', 'magic_percent' => 'mag', 'spirit_percent' => 'spr', 'speed_percent' => 'agi', 'hp_percent' => 'max_hp', default => null };
            if ($column) $enemy->{$column} = max(1, (int) floor((int) $enemy->{$column} * (1 + ((float) $percent / 100))));
        }
        $this->difficulty->applyToEnemy($enemy, $this->difficulty->targetLevel($enemy, $variant, (string) $map->map_grade));
        $stamina = app(ExplorationStaminaService::class);
        if ($stamina->enabled()) {
            $consumed = $stamina->consumeForExplore($character);
            if (!($consumed['ok'] ?? false)) throw new \RuntimeException((string) ($consumed['error'] ?? '探索力が足りません。'));
        }
        $modifiers = $map->reward_modifiers_json ?? [];
        $battle = app(BattleService::class)->executeBattle($character, $enemy, (int) ($modifiers['gold_drop_rate_bonus_points'] ?? 0));
        $win = $battle->result === 'victory';
        $exp = 0; $gold = 0; $jobExp = 0; $drops = ['materials' => [], 'equipment' => []];
        $rewardResult = ['level_up_count' => 0, 'details' => [], 'progression' => null];
        $mapDrop = null;
        $valmonEggFound = null;
        if ($win) {
            $mapRewards = $this->rewards->rewardsFor($enemy, $map, (int) $battle->gold);
            $exp = $mapRewards['experience'];
            $gold = $mapRewards['gold'];
            $jobExpCap = max(LevelService::MAX_JOB_EXP_GAIN, (int) ($modifiers['job_exp_cap'] ?? LevelService::MAX_JOB_EXP_GAIN));
            $jobExp = app(LevelService::class)->capJobExpGain(
                (int) floor((int) $battle->jobExp * (float) ($modifiers['job_exp_multiplier'] ?? 1)),
                $jobExpCap,
            );
            $rewardResult = app(LevelService::class)->addRewardAndCheckLevelUp($character, $exp, $gold, $jobExp, $jobExpCap);
            $drops = app(DropService::class)->rollBattleDrops(
                $character,
                $enemy,
                $battle->dropBonusPercent,
                $battle->rareBonusPercent,
                false,
                false,
                (float) config('exploration_maps.material_drop.common_material_weight_multiplier', 0.5),
                [
                    'material' => (float) ($modifiers['material_drop_bonus_points'] ?? 0),
                    ...((array) ($modifiers['equipment_drop_bonus_points'] ?? [])),
                ],
            );
            $this->applyVictoryRecovery($character, $battle, $modifiers);
            $mapDrop = app(ExplorationMapDropService::class)->tryDrop($character, $map->sourceArea, $enemy, false, true);
            $valmonEggFound = app(ValmonService::class)->tryFindEgg($character, $map->sourceArea, null);
            $character->increment('wins');
        } else {
            $character->increment('losses');
            $stats = app(CharacterStatusService::class)->getFinalStats($character);
            $character->update(['current_hp' => max(1, (int) floor(((int) ($stats['max_hp'] ?? $character->hp_base)) * .3))]);
        }
        $logText = implode('<br>', $battle->logs);
        if ($valmonEggFound) {
            $logText .= "<br><span class=\"text-pink-700 font-extrabold\">【ヴァルモンの卵】{$valmonEggFound['message']}</span>";
        }
        app(BattleLogService::class)->addLog($character, $map->source_area_id, $enemy->id, 'exploration_map', $win ? 'win' : 'lose', $exp, $gold, $jobExp, (int) ($rewardResult['level_up_count'] ?? 0), $logText);
        MapExplorationResult::create(['batch_id' => $batch->id, 'map_id' => $map->id, 'registration_id' => $batch->registration_id, 'character_id' => $character->id, 'global_exploration_index' => $index, 'encounter_seed_hash' => hash('sha256', $encounterSeed), 'reward_seed_hash' => hash('sha256', $rewardSeed), 'monster_variants_json' => $variant, 'battle_result' => $battle->result, 'experience' => $exp, 'gold' => $gold, 'drops_json' => ['materials' => $drops['materials'] ?? [], 'equipment' => $drops['equipment'] ?? []]]);

        return [
            'success' => true,
            'result' => $battle->result,
            'turn_count' => $battle->turnCount,
            'log' => $logText,
            'enemy' => $enemy,
            'exp_gained' => $exp,
            'gold_gained' => $gold,
            'job_exp_gained' => $jobExp,
            'enemy_stat_display' => $battle->enemyStatDisplay ?? [],
            'level_up_count' => (int) ($rewardResult['level_up_count'] ?? 0),
            'level_up_details' => $rewardResult['details'] ?? [],
            'progression' => $rewardResult['progression'] ?? null,
            'material_drop' => $drops['materials'] ?? [],
            'equipment_drops' => $drops['equipment'] ?? [],
            'drop_results' => [],
            'map_drop' => $mapDrop,
            'valmon_egg_found' => $valmonEggFound,
            'exploration_stamina' => app(ExplorationStaminaService::class)->summary($character),
            'new_discoveries' => [],
        ];
    }

    private function savedBattleResult(MapExplorationBatch $batch): array
    {
        $result = $batch->results()->latest('global_exploration_index')->first();
        if (!$result) return ['error' => 'この地図探索の結果を読み込めませんでした。'];

        $variant = $result->monster_variants_json ?? [];
        $enemy = Enemy::find($variant['base_monster_id'] ?? null);
        if ($enemy) {
            $enemy = clone $enemy;
            $enemy->name = (string) ($variant['display_name'] ?? $enemy->name);
            $this->difficulty->applyToEnemy($enemy, $this->difficulty->targetLevel($enemy, $variant, (string) $batch->map->map_grade));
        }

        return [
            'success' => true,
            'result' => $result->battle_result,
            'turn_count' => 0,
            'log' => '地図探索の結果を再表示しています。',
            'enemy' => $enemy,
            'exp_gained' => (int) $result->experience,
            'gold_gained' => (int) $result->gold,
            'job_exp_gained' => 0,
            'enemy_stat_display' => [],
            'level_up_count' => 0,
            'level_up_details' => [],
            'progression' => null,
            'material_drop' => $result->drops_json['materials'] ?? [],
            'equipment_drops' => $result->drops_json['equipment'] ?? [],
            'drop_results' => [],
            'exploration_stamina' => app(ExplorationStaminaService::class)->summary($batch->character),
            'new_discoveries' => [],
        ];
    }

    /**
     * 通常探索の10回探索と同じ結果画面で表示するための集計データを付ける。
     * 1回探索では最後の戦闘結果をそのまま返す。
     *
     * @param array<int, array<string, mixed>> $runs
     * @param array<string, mixed> $lastResult
     * @return array<string, mixed>
     */
    private function presentBattleResult(MapExplorationBatch $batch, array $lastResult, array $runs): array
    {
        if ((int) $batch->requested_count <= 1) {
            return $lastResult;
        }

        $totalExp = 0;
        $totalGold = 0;
        $totalJobExp = 0;
        $materials = [];
        $equipment = [];
        $levelUps = [];
        $summaryLines = [];

        foreach ($runs as $offset => $run) {
            $totalExp += (int) ($run['exp_gained'] ?? 0);
            $totalGold += (int) ($run['gold_gained'] ?? 0);
            $totalJobExp += (int) ($run['job_exp_gained'] ?? 0);
            $levelUps = array_merge($levelUps, $run['level_up_details'] ?? []);
            $equipment = array_merge($equipment, $run['equipment_drops'] ?? []);

            foreach ($run['material_drop'] ?? [] as $drop) {
                $key = (string) ($drop['material_id'] ?? $drop['material_code'] ?? $drop['name'] ?? count($materials));
                if (isset($materials[$key])) {
                    $materials[$key]['quantity'] = (int) ($materials[$key]['quantity'] ?? 1) + (int) ($drop['quantity'] ?? 1);
                } else {
                    $materials[$key] = $drop + ['quantity' => 1];
                }
            }

            $summaryLines[] = sprintf(
                '%d回目: %s / EXP +%s / Job EXP +%s / Gold +%sG',
                $offset + 1,
                (string) data_get($run, 'enemy.name', '魔物'),
                number_format((int) ($run['exp_gained'] ?? 0)),
                number_format((int) ($run['job_exp_gained'] ?? 0)),
                number_format((int) ($run['gold_gained'] ?? 0)),
            );
        }

        $completed = count($runs);
        $stopReason = (($lastResult['result'] ?? null) === 'victory' || ($lastResult['result'] ?? null) === 'win') ? 'completed' : 'defeat';
        $stopText = $completed < (int) $batch->requested_count
            ? '探索可能回数が尽きたか、敗北したため途中で探索を止めました。'
            : '';

        $lastResult['log'] = implode('<br>', $summaryLines);
        $lastResult['exp_gained'] = $totalExp;
        $lastResult['gold_gained'] = $totalGold;
        $lastResult['job_exp_gained'] = $totalJobExp;
        $lastResult['level_up_count'] = count($levelUps);
        $lastResult['level_up_details'] = $levelUps;
        $lastResult['material_drop'] = array_values($materials);
        $lastResult['equipment_drops'] = $equipment;
        $lastResult['drop'] = $equipment[0] ?? null;
        $lastResult['batch_explore'] = [
            'requested' => (int) $batch->requested_count,
            'completed' => $completed,
            'stop_reason' => $stopReason,
            'stop_text' => $stopText,
            'total_exp' => $totalExp,
            'total_gold' => $totalGold,
            'total_job_exp' => $totalJobExp,
            'total_kiseki' => 0,
            'runs' => [],
        ];

        return $lastResult;
    }

    /** @param array<string, mixed> $modifiers */
    private function applyVictoryRecovery(Character $character, object $battle, array $modifiers): void
    {
        $hpPercent = max(0, (float) ($modifiers['victory_hp_recovery_percent'] ?? 0));
        $spPercent = max(0, (float) ($modifiers['victory_sp_recovery_percent'] ?? 0));
        if ($hpPercent <= 0 && $spPercent <= 0) {
            return;
        }

        $stats = app(CharacterStatusService::class)->getFinalStats($character);
        $maxHp = max(1, (int) ($stats['max_hp'] ?? $character->hp_base));
        $maxSp = max(0, (int) ($stats['max_mp'] ?? 0));
        $hpRecovery = $hpPercent > 0 ? max(1, (int) floor($maxHp * $hpPercent / 100)) : 0;
        $spRecovery = $spPercent > 0 && $maxSp > 0 ? max(1, (int) floor($maxSp * $spPercent / 100)) : 0;
        $beforeHp = (int) $character->current_hp;
        $beforeSp = (int) ($character->current_mp ?? 0);

        $character->update([
            'current_hp' => min($maxHp, $beforeHp + $hpRecovery),
            'current_mp' => min($maxSp, $beforeSp + $spRecovery),
        ]);
        $character->refresh();

        $actualHp = max(0, (int) $character->current_hp - $beforeHp);
        $actualSp = max(0, (int) ($character->current_mp ?? 0) - $beforeSp);
        if ($actualHp > 0 || $actualSp > 0) {
            $battle->logs[] = '<br><span class="text-emerald-700 font-bold">【精気の余韻】HP +' . $actualHp . ' / SP +' . $actualSp . ' 回復した！</span>';
        }
        $battle->playerHpAfter = (int) $character->current_hp;
        $battle->playerMpAfter = (int) ($character->current_mp ?? 0);
    }

    private function refundUnexecuted(Character $character, MapExplorationBatch $batch, int $count): void
    {
        DB::transaction(function () use ($character, $batch, $count) {
            $registration = TownMapRegistration::lockForUpdate()->findOrFail($batch->registration_id);
            $registration->increment('remaining_explorations', $count);
            $registration->decrement('consumed_explorations', $count);
            $refund = $batch->executed_count === 0 ? $batch->total_fee : 0;
            if ($refund > 0) app(GoldService::class)->add($character, $refund, 'map_entry_fee_refund', '未実行の探索地図入場料を返還', MapExplorationBatch::class, $batch->id);
            $batch->update(['reserved_count' => $batch->executed_count, 'last_exploration_index' => $batch->first_exploration_index + max(0, $batch->executed_count - 1), 'total_fee' => $batch->executed_count > 0 ? $batch->total_fee : 0]);
        });
    }

    private function summary(MapExplorationBatch $batch): array
    {
        $results = $batch->results()->get();
        $enemies = $results->groupBy(fn ($result) => $result->monster_variants_json['display_name'] ?? '不明な魔物')->map->count()->all();
        return ['requested_count' => $batch->requested_count, 'executed_count' => $results->count(), 'experience' => $results->sum('experience'), 'gold' => $results->sum('gold'), 'enemies' => $enemies, 'depleted' => (int) TownMapRegistration::find($batch->registration_id)?->remaining_explorations <= 0];
    }
}
