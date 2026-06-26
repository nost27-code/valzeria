<?php

namespace App\Services;

use App\Models\Character;
use App\Models\CharacterSubAreaRouteDiscovery;
use App\Models\Enemy;

class SubAreaExplorationService
{
    public function __construct(
        private BattleService $battleService,
        private LevelService $levelService,
        private BattleLogService $battleLogService,
        private DropService $dropService,
        private SubAreaExplorationStateService $stateService
    ) {
    }

    public function explore(Character $character, CharacterSubAreaRouteDiscovery $discovery): array
    {
        $discovery->loadMissing('route.subArea', 'route.sourceArea');
        $route = $discovery->route;
        $subArea = $route?->subArea;
        $sourceArea = $route?->sourceArea;

        if (!$route || !$subArea || !$sourceArea || !$subArea->is_enabled || !$route->is_enabled) {
            return ['error' => 'この入口は現在利用できません。'];
        }

        if ($character->current_hp <= 0) {
            return ['error' => 'HPがありません。宿屋で休んでください。'];
        }

        if ($character->exploration_cooldown_until && now()->lt($character->exploration_cooldown_until)) {
            $remaining = (int) ceil(now()->diffInSeconds($character->exploration_cooldown_until, false));
            return ['error' => "宿屋で休んだ直後です。あと {$remaining} 秒待ってください。"];
        }

        $depthKey = $subArea?->layer_type === 'otherworld' ? 'otherworld' : 'deep';
        $battleCooldownSeconds = app(CooldownSettingService::class)->explorationBattleSecondsForDepthKey($depthKey);
        if ($battleCooldownSeconds > 0 && $character->last_battle_at) {
            $elapsed = $character->last_battle_at->lte(now())
                ? (int) $character->last_battle_at->diffInSeconds(now(), true)
                : 0;
            if ($elapsed >= $battleCooldownSeconds) {
                $elapsed = $battleCooldownSeconds;
            }
            if ($elapsed < $battleCooldownSeconds) {
                $remaining = $battleCooldownSeconds - $elapsed;
                return ['error' => "連続で戦闘はできません。あと {$remaining} 秒待ってください。"];
            }
        }

        $character->last_battle_at = now();
        $character->save();

        $state = $this->stateService->getOrStart($character, $discovery);
        $enemy = $this->pickEnemy((int) $sourceArea->id);
        if (!$enemy) {
            return ['error' => 'この入口の先には、まだ敵が設定されていません。'];
        }

        $targetEnemy = $this->scaleEnemyForSubArea($enemy, $subArea, (int) $state->danger_rate);
        $battleResult = $this->battleService->executeBattle($character, $targetEnemy);
        $isWin = $battleResult->result === 'victory';
        $logText = implode('<br>', $battleResult->logs);

        $expGained = 0;
        $goldGained = 0;
        $jobExpGained = 0;
        $levelUpCount = 0;
        $levelUpDetails = [];
        $dropResults = [
            'materials' => [],
            'equipment' => [],
            'monster_mark' => null,
            'by_slot' => ['material' => [], 'weapon' => null, 'armor' => null, 'accessory' => null, 'monster_mark' => null],
        ];
        $dropResult = null;
        $equipmentDropResults = [];
        $materialDropResult = [];
        $monsterMarkDrop = null;
        $progress = null;

        if ($isWin) {
            $character->wins += 1;
            $expGained = max(1, (int) floor($battleResult->exp * 1.8));
            $goldGained = (int) $battleResult->gold;
            $jobExpGained = max(1, (int) ceil(max(1, $battleResult->jobExp) * 1.5));
            $battleResult->exp = $expGained;
            $battleResult->jobExp = $jobExpGained;

            $rewardResult = $this->levelService->addRewardAndCheckLevelUp($character, $expGained, $goldGained, $jobExpGained);
            $levelUpCount = $rewardResult['level_up_count'];
            $levelUpDetails = $rewardResult['details'];

            $jobResult = $rewardResult['job_result'] ?? null;
            if ($jobResult) {
                if ($jobResult['mastered']) {
                    $logText .= "<br><span class=\"text-blue-600 font-bold\">【職業マスター】{$jobResult['job_name']}を極めた！</span>";
                } elseif ($jobResult['level_up']) {
                    $logText .= "<br><span class=\"text-blue-600 font-bold\">【ランクアップ】{$jobResult['job_name']}のランクが {$jobResult['job_level']} に上がった！</span>";
                }
            }

            $dropBonus = (int) min(20, floor(((int) $state->danger_rate) / 10));
            $rareBonus = (int) min(10, floor(((int) $state->danger_rate) / 20));
            $dropResults = $this->dropService->rollBattleDrops($character, $targetEnemy, $dropBonus + 8, $rareBonus + 3, false);
            $equipmentDropResults = $dropResults['equipment'] ?? [];
            $materialDropResult = $dropResults['materials'] ?? [];
            $monsterMarkDrop = $dropResults['monster_mark'] ?? null;
            $dropResult = $equipmentDropResults[0] ?? null;

            $progress = $this->stateService->recordVictory($character, $discovery, $targetEnemy);
            $danger = $progress['danger'] ?? null;
            $logText .= "<br><span class=\"text-indigo-800 font-extrabold\">【共有サブエリア】{$subArea->name}の探索度が +{$progress['added_point']} 進みました。</span>";
            if ($danger && ($danger['increased'] ?? false)) {
                $logText .= "<br><span class=\"text-orange-700 font-bold\">【危険度】+{$danger['increase']}%（{$danger['before']}% → {$danger['after']}% / {$danger['label']}）</span>";
            }
            foreach ($progress['milestones'] ?? [] as $milestone) {
                $logText .= "<br><span class=\"text-sky-700 font-bold\">【探索】{$milestone['message']}</span>";
            }
        } else {
            $character->losses += 1;
            $this->stateService->reset($character);
            $logText .= "<br><span class=\"text-red-700 font-bold\">【撤退】共有サブエリアの入口まで退きました。サブエリア探索は終了します。</span>";
        }

        $character->battles += 1;
        $character->save();

        $battleLog = $this->battleLogService->addLog(
            $character,
            (int) $sourceArea->id,
            (int) $enemy->id,
            'sub_area',
            $battleResult->result,
            $expGained,
            $goldGained,
            $levelUpCount,
            $logText,
            $dropResult['item_id'] ?? null,
            $dropResult['character_item_id'] ?? null
        );

        return [
            'success' => true,
            'result' => $battleResult->result,
            'log' => $logText,
            'enemy' => $targetEnemy,
            'exp_gained' => $expGained,
            'gold_gained' => $goldGained,
            'job_exp_gained' => $jobExpGained,
            'enemy_stat_display' => $battleResult->enemyStatDisplay ?? [],
            'level_up_count' => $levelUpCount,
            'level_up_details' => $levelUpDetails,
            'unlocked_areas' => [],
            'drop' => $dropResult,
            'equipment_drops' => $equipmentDropResults,
            'material_drop' => $materialDropResult,
            'monster_mark_drop' => $monsterMarkDrop,
            'kiseki_drop' => null,
            'drop_results' => $dropResults,
            'material_penalty' => null,
            'chain_loot_summary' => null,
            'exploration_progress' => $progress,
            'exploration_summary' => $this->stateService->summary($character, $discovery),
            'special_event' => 'sub_area_explore',
            'sub_area_name' => $subArea->name,
            'sub_area_route_name' => $route->route_name,
            'sub_area_discovery_id' => $discovery->id,
            'secret_realm_image' => null,
            'secret_realm_name' => null,
            'rescue_fee' => null,
            'rescue_support' => null,
            'valmon_egg_found' => null,
            'valmon_material_find' => null,
            'valmon_egg_lost' => [],
            'battle_log_id' => $battleLog->id,
        ];
    }

    private function pickEnemy(int $sourceAreaId): ?Enemy
    {
        $enemies = Enemy::where('area_id', $sourceAreaId)
            ->where('is_boss', false)
            ->get();

        if ($enemies->isEmpty()) {
            return null;
        }

        $totalWeight = max(0, (int) $enemies->sum('appearance_weight'));
        if ($totalWeight <= 0) {
            return $enemies->random();
        }

        $roll = random_int(1, $totalWeight);
        $current = 0;
        foreach ($enemies as $enemy) {
            $current += (int) $enemy->appearance_weight;
            if ($roll <= $current) {
                return $enemy;
            }
        }

        return $enemies->first();
    }

    private function scaleEnemyForSubArea(Enemy $enemy, $subArea, int $dangerRate): Enemy
    {
        $targetMin = max(1, (int) ($subArea->recommended_level_min ?? $enemy->level));
        $targetMax = max($targetMin, (int) ($subArea->recommended_level_max ?? $targetMin));
        $targetLevel = random_int($targetMin, $targetMax);
        $baseLevel = max(1, (int) ($enemy->level ?? 1));
        $levelScale = max(1.0, $targetLevel / $baseLevel);
        $dangerScale = 1.0 + (max(0, $dangerRate) * 0.003);
        $statScale = max(1.0, ($levelScale ** 1.22) * $dangerScale);
        $hpScale = max(1.0, ($levelScale ** 1.95) * $dangerScale);
        $rewardScale = max(1.0, ($levelScale ** 1.35) * 1.35);

        $enemy->forceFill([
            'name' => "{$subArea->name}の{$enemy->name}",
            'level' => $targetLevel,
            'max_hp' => max(1, (int) floor((int) $enemy->max_hp * $hpScale)),
            'str' => max(1, (int) floor((int) $enemy->str * $statScale)),
            'def' => max(1, (int) floor((int) $enemy->def * $statScale)),
            'agi' => max(1, (int) floor((int) $enemy->agi * $statScale)),
            'mag' => max(1, (int) floor((int) $enemy->mag * $statScale)),
            'spr' => max(1, (int) floor((int) ($enemy->spr ?? $enemy->def) * $statScale)),
            'exp_reward' => max(1, (int) floor((int) $enemy->exp_reward * $rewardScale)),
            'job_exp_reward' => max(1, (int) ceil(max(1, (int) $enemy->job_exp_reward) * min(5.0, $levelScale))),
            'gold_reward' => max(1, (int) floor(max(1, (int) $enemy->gold_reward) * min(5.0, $rewardScale))),
            'skip_danger_bonus' => true,
        ]);

        return $enemy;
    }
}
