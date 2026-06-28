<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterMaterial;
use App\Models\Enemy;
use App\Models\Material;
use App\Models\MaterialDrop;
use App\Services\Battle\BattleResult;

class ExplorationService
{
    private const COMMON_MONSTER_FRAGMENT_CODE = 'MAT_COMMON_MONSTER_FRAGMENT';
    private const LEGACY_COMMON_FRAGMENT_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];
    private const GOLDEN_GOBLIN_REWARD_MIN_MULTIPLIER = 2.0;
    private const GOLDEN_GOBLIN_REWARD_MAX_MULTIPLIER = 3.0;

    protected BattleService $battleService;
    protected LevelService $levelService;
    protected AreaService $areaService;
    protected BattleLogService $battleLogService;
    protected DropService $dropService;
    protected PublicLogService $publicLogService;
    protected KisekiDropService $kisekiDropService;
    protected DiscoveryService $discoveryService;

    public function __construct(
        BattleService $battleService,
        LevelService $levelService,
        AreaService $areaService,
        BattleLogService $battleLogService,
        DropService $dropService,
        PublicLogService $publicLogService,
        KisekiDropService $kisekiDropService,
        DiscoveryService $discoveryService
    ) {
        $this->battleService = $battleService;
        $this->levelService = $levelService;
        $this->areaService = $areaService;
        $this->battleLogService = $battleLogService;
        $this->dropService = $dropService;
        $this->publicLogService = $publicLogService;
        $this->kisekiDropService = $kisekiDropService;
        $this->discoveryService = $discoveryService;
    }

    /**
     * 探索のメイン処理
     */
    public function explore(Character $character, int $areaId, bool $isBossBattle = false, ?string $forcedEvent = null, bool $skipBattleCooldown = false): array
    {
        // 1. クールタイム・HPチェック
        if ($character->current_hp <= 0) {
            return ['error' => 'HPがありません。宿屋で休んでください。'];
        }

        if ($character->exploration_cooldown_until && now()->lt($character->exploration_cooldown_until)) {
            $remaining = (int) ceil(now()->diffInSeconds($character->exploration_cooldown_until, false));
            return ['error' => "宿屋で休んだ直後です。あと {$remaining} 秒待ってください。"];
        }

        $area = Area::findOrFail($areaId);
        $explorationStateService = app(ExplorationStateService::class);
        $currentState = !$isBossBattle ? $explorationStateService->currentFor($character) : null;
        $currentDanger = $currentState && (int) $currentState->area_id === $areaId
            ? (int) ($currentState->danger_rate ?? 0)
            : 0;
        $currentPoint = $currentState && (int) $currentState->area_id === $areaId
            ? (int) ($currentState->exploration_point ?? 0)
            : 0;
        $currentDepthTier = !$isBossBattle
            ? app(ExplorationDepthService::class)->activeTierFor($character, $area, $currentPoint, $currentDanger)
            : ['key' => 'surface'];
        $battleCooldownSeconds = app(CooldownSettingService::class)->explorationBattleSecondsForDepthTier($currentDepthTier);
        if (!$skipBattleCooldown && !$isBossBattle && $forcedEvent !== 'dungeon_lord' && $battleCooldownSeconds > 0 && $character->last_battle_at) {
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
        $state = !$isBossBattle ? $explorationStateService->getOrStart($character, $areaId) : null;

        // 2. 敵の抽選
        $enemyQuery = Enemy::where('area_id', $areaId);
        if ($isBossBattle) {
            $enemyQuery->where('is_boss', true);
        } else {
            $enemyQuery->where('is_boss', false);
        }
        
        $enemies = $enemyQuery->get();
        if ($enemies->isEmpty()) {
            return ['error' => 'このエリアには敵が存在しません。'];
        }

        // appearance_weight に基づく抽選
        $totalWeight = $enemies->sum('appearance_weight');
        $targetEnemy = null;

        if ($totalWeight <= 0) {
            // マスターデータのウェイトが未設定・全0の場合は、対象から完全に均等確率で1体を抽選する
            $targetEnemy = $enemies->random();
        } else {
            $rand = rand(1, $totalWeight);
            $currentWeight = 0;
            
            foreach ($enemies as $enemy) {
                $currentWeight += $enemy->appearance_weight;
                if ($rand <= $currentWeight) {
                    $targetEnemy = $enemy;
                    break;
                }
            }
        }

        if (!$targetEnemy) {
            $targetEnemy = $enemies->first();
        }

        $specialEvent = null;
        if (!$isBossBattle && $state) {
            $specialEvent = $forcedEvent === 'dungeon_lord'
                ? ['type' => 'dungeon_lord', 'enemy' => $this->makeDungeonLordEnemy($area, $targetEnemy)]
                : $this->rollSpecialEvent($character, $area, $targetEnemy, $state);
            if (($specialEvent['enemy'] ?? null) instanceof Enemy) {
                $targetEnemy = $specialEvent['enemy'];
            }
        }

        // 3. バトル実行
        $battleResult = in_array(($specialEvent['type'] ?? null), ['treasure', 'hidden_area_gate', 'dungeon_lord_encounter', 'sub_area_gate'], true)
            ? $this->resolveNonCombatEvent($character, $area, $specialEvent['source_enemy'] ?? $targetEnemy, $specialEvent['type'], $specialEvent)
            : $this->battleService->executeBattle($character, $targetEnemy);
        $isWin = $battleResult->result === 'victory';
        $isEventOnly = $battleResult->result === 'event';
        $logText = implode("<br>", $battleResult->logs);

        $expGained = 0;
        $goldGained = 0;
        $jobExpGained = 0;
        $levelUpCount = 0;
        $levelUpDetails = [];
        $unlockedAreas = [];
        $dropResult = null;
        $dropResults = ['materials' => [], 'equipment' => [], 'by_slot' => []];
        $materialDropResult = [];
        $equipmentDropResults = [];
        $monsterMarkDrop = null;
        $secretRealmRewards = [];
        $kisekiDrop = null;
        $materialPenalty = ['total_lost' => 0, 'materials' => [], 'items' => []];
        $chainLootSummary = ['materials' => [], 'items' => [], 'material_total' => 0, 'item_total' => 0, 'risk_total' => 0];
        $explorationProgress = null;
        $explorationSummary = null;
        $developmentResult = null;
        $newDiscoveries = [];
        $goldLoss = null;
        $rescueSupport = null;
        $valmonEggFound = null;
        $valmonMaterialFind = null;
        $valmonDiscoveryHint = null;
        $valmonRecovery = null;
        $valmonEggLost = [];
        $materialHuntCompletion = null;

        // 4. 勝敗に応じた処理
        if ($isEventOnly) {
            if (!$isBossBattle) {
                $chainLootSummary = $explorationStateService->currentLootSummary($character, $areaId);
            }
        } elseif ($isWin) {
            $character->wins += 1;
            $expGained = $battleResult->exp;
            $goldGained = $battleResult->gold;
            $jobExpGained = $battleResult->jobExp;

            if (!$isBossBattle && !in_array(($specialEvent['type'] ?? null), ['treasure', 'hidden_area_gate', 'sub_area_gate'], true)) {
                $depthReward = $this->depthRewardBonus($character, $area, $state);
                if (($depthReward['multiplier'] ?? 1.0) > 1.0) {
                    $expGained = max(1, (int) floor($expGained * $depthReward['multiplier']));
                    if ($jobExpGained > 0) {
                        $jobExpGained = max(1, (int) ceil($jobExpGained * $depthReward['multiplier']));
                    }
                    $jobExpGained = $this->levelService->capJobExpGain($jobExpGained);
                    $battleResult->exp = $expGained;
                    $battleResult->jobExp = $jobExpGained;
                }
            }

            if (($specialEvent['type'] ?? null) === 'golden_goblin') {
                $goldenGoblinGold = $this->goldenGoblinGoldReward($area);
                $goldGained += $goldenGoblinGold;
                $battleResult->gold = $goldGained;
                $logText .= "<br><span class=\"text-amber-700 font-bold\">【黄金ゴブリン】きらめく小袋から <span class=\"text-amber-600 font-extrabold\">{$goldenGoblinGold}G</span> を手に入れた！</span>";
            }

            // レベルアップ処理
            $rewardResult = $this->levelService->addRewardAndCheckLevelUp($character, $expGained, $goldGained, $jobExpGained);
            $levelUpCount = $rewardResult['level_up_count'];
            $levelUpDetails = $rewardResult['details'];

            $jobResult = $rewardResult['job_result'] ?? null;
            if ($jobResult) {
                if ($jobResult['mastered']) {
                    $logText .= "<br><span class=\"text-blue-600 font-bold\">【職業マスター】{$jobResult['job_name']}を極めた！</span>";
                } elseif ($jobResult['level_up']) {
                    if (isset($jobResult['old_level']) && ($jobResult['job_level'] - $jobResult['old_level']) > 1) {
                        $logText .= "<br><span class=\"text-blue-600 font-bold\">【ランクアップ】{$jobResult['job_name']}のランクが {$jobResult['old_level']} から {$jobResult['job_level']} に上がった！</span>";
                    } else {
                        $logText .= "<br><span class=\"text-blue-600 font-bold\">【ランクアップ】{$jobResult['job_name']}のランクが {$jobResult['job_level']} に上がった！</span>";
                    }
                }
            }

            // ボス討伐ならエリア解放
            if ($isBossBattle) {
                $unlockedAreas = $this->areaService->unlockNextArea($character, $areaId);
                $discoveryResult = $this->discoveryService->checkAfterBoss($character, $area);
                $newDiscoveries = array_merge($newDiscoveries, $discoveryResult['discoveries'] ?? []);
            }

            // 称号チェック
            $titleUnlockService = app(\App\Services\TitleUnlockService::class);
            $unlockedTitles = [];
            $unlockedTitles = array_merge($unlockedTitles, $titleUnlockService->checkBattleTitles($character));
            if ($isBossBattle) {
                $unlockedTitles = array_merge($unlockedTitles, $titleUnlockService->checkAreaClearTitles($character));
            }

            // 職業関連の称号が含まれるかもしれないので念のためまとめてチェックする
            $unlockedTitles = array_merge($unlockedTitles, $titleUnlockService->checkJobTitles($character));

            foreach ($unlockedTitles as $title) {
                $logText .= "<br><span class=\"text-orange-500 font-bold\"><img src=\"/images/icon/icon_010.webp\" alt=\"\" style=\"width:14px;height:14px;object-fit:contain;display:inline-block;vertical-align:middle;\"> 称号【{$title->name}】を獲得した！</span>";
            }

            // ドロップ抽選（素材・武器・防具・装飾品を独立枠で判定）
            if (($specialEvent['type'] ?? null) === 'secret_realm_lord') {
                $realmReward = app(SecretRealmService::class)->grantLordRewards(
                    $character,
                    $area,
                    $targetEnemy,
                    $specialEvent['secret_realm'] ?? app(SecretRealmService::class)->realmForArea($area),
                    $this->dropService
                );
                $secretRealmRewards = $realmReward['drops'] ?? [];
                foreach ($realmReward['logs'] ?? [] as $rewardLog) {
                    $logText .= "<br><span class=\"text-emerald-700 font-bold\">{$rewardLog}</span>";
                }
            }

            if (!in_array(($specialEvent['type'] ?? null), ['treasure', 'hidden_area_gate', 'sub_area_gate'], true)) {
                $dropResults = $this->dropService->rollBattleDrops(
                    $character,
                    $targetEnemy,
                    $battleResult->dropBonusPercent,
                    $battleResult->rareBonusPercent
                );
            }
            $materialDropResult = in_array(($specialEvent['type'] ?? null), ['treasure', 'hidden_area_gate', 'sub_area_gate'], true)
                ? ($battleResult->drops ?? [])
                : ($dropResults['materials'] ?? []);
            if (!empty($secretRealmRewards)) {
                $materialDropResult = array_merge($secretRealmRewards, $materialDropResult);
                $dropResults['materials'] = array_merge($secretRealmRewards, $dropResults['materials'] ?? []);
                $dropResults['by_slot']['material'] = array_merge($secretRealmRewards, $dropResults['by_slot']['material'] ?? []);
            }

            if (($specialEvent['type'] ?? null) === 'golden_goblin') {
                $logText .= "<br><span class=\"text-amber-700 font-bold\">【黄金ゴブリン】逃げ足の速い黄金ゴブリンを倒した！</span>";
            }

            $equipmentDropResults = $dropResults['equipment'] ?? [];
            $monsterMarkDrop = $dropResults['monster_mark'] ?? null;
            $dropResult = $equipmentDropResults[0] ?? null;

            // 素材ドロップのログ追加
            if (!empty($materialDropResult)) {
                $materialNames = array_column($materialDropResult, 'name');
                $logText .= "<br><span class=\"text-green-600 font-bold\">【素材獲得】" . implode('、', $materialNames) . " を手に入れた！</span>";
            }

            if (!empty($equipmentDropResults)) {
                $equipmentNames = array_map(
                    fn (array $drop) => ($drop['slot_label'] ?? '装備') . '「' . $drop['item_name'] . '」',
                    $equipmentDropResults
                );
                $logText .= "<br><span class=\"text-indigo-600 font-bold\">【装備獲得】" . implode('、', $equipmentNames) . " を手に入れた！</span>";
            }

            if ($monsterMarkDrop) {
                $logText .= "<br><span class=\"text-fuchsia-700 font-bold\">【印獲得】{$monsterMarkDrop['name']} を手に入れた！</span>";
                if ($monsterMarkDrop['level_up']) {
                    $logText .= "<br><span class=\"text-fuchsia-700 font-bold\">【印図鑑】{$monsterMarkDrop['name']} 段階{$monsterMarkDrop['unlocked_level']} 解放！ {$monsterMarkDrop['bonus_stat_label']} +{$monsterMarkDrop['total_bonus']}</span>";
                }
            }

            $kisekiDrop = $this->kisekiDropService->tryDropFromBattle(
                $character,
                $targetEnemy,
                $area,
                $isBossBattle ? 'boss' : 'normal'
            );
            if ($kisekiDrop) {
                $amount = (int) ($kisekiDrop['amount'] ?? 1);
                $logText .= "<br><span class=\"text-sky-700 font-extrabold\">【輝石獲得】戦利品の中に、淡く輝く石が混じっていた。輝石 x{$amount} を手に入れた！</span>";
            }

            // レア以上の場合は公開ログ
            foreach ($equipmentDropResults as $equipmentDrop) {
                $rankText = strtoupper((string) ($equipmentDrop['rank'] ?? $equipmentDrop['rarity'] ?? ''));
                $rarity = strtolower((string) ($equipmentDrop['rarity'] ?? ''));

                if ($rankText === 'LEGEND' || $rarity === 'legend') {
                    $rankText = 'EPIC';
                }

                if (($equipmentDrop['affix_quality'] ?? null) === 'excellent') {
                    $this->publicLogService->addLog(
                        'drop',
                        "【逸品】{$character->name}さんが「{$equipmentDrop['item_name']}」を手に入れました！",
                        $character,
                        3
                    );
                    continue;
                }

                if (!in_array($rankText, ['SSS', 'EPIC'], true)
                    && !in_array($rarity, ['rare', 'epic', 'legend'], true)) {
                    continue;
                }

                $message = "【獲得】{$character->name}さんが{$rankText}ランク装備「{$equipmentDrop['item_name']}」を手に入れました！";
                $importance = in_array($rankText, ['SSS', 'EPIC'], true) ? 3 : 2;

                $this->publicLogService->addLog('drop', $message, $character, $importance);
            }

            if (!$isBossBattle) {
                if (!in_array(($specialEvent['type'] ?? null), ['treasure', 'hidden_area_gate', 'sub_area_gate'], true)) {
                    $explorationProgress = $explorationStateService->recordVictory($character, $targetEnemy);
                    $danger = $explorationProgress['danger'] ?? null;
                    if ($danger && ($danger['increased'] ?? false)) {
                        $logText .= "<br><span class=\"text-orange-700 font-bold\">【危険度】+{$danger['increase']}%（{$danger['before']}% → {$danger['after']}% / {$danger['label']}）</span>";
                        if (!empty($danger['floor_applied'])) {
                            $logText .= "<br><span class=\"text-red-700 font-bold\">【探索深度】この層の最低危険度まで引き上がりました。</span>";
                        }
                    } elseif ($danger) {
                        $logText .= "<br><span class=\"text-slate-600 font-bold\">【危険度】変化なし（現在 {$danger['after']}% / {$danger['label']}）</span>";
                    }

                    foreach ($explorationProgress['milestones'] ?? [] as $milestone) {
                        $logText .= "<br><span class=\"text-sky-700 font-bold\">【探索】{$milestone['message']}</span>";
                    }

                    foreach ($explorationProgress['depth_transitions'] ?? [] as $depthTier) {
                        $message = $depthTier['message'] ?? (($depthTier['label'] ?? '深部') . 'に到達しました。');
                        $logText .= "<br><span class=\"text-indigo-800 font-extrabold\">【探索深度】{$message}</span>";
                    }

                    $hasDepthTransition = !empty($explorationProgress['depth_transitions'] ?? []);
                    $discoveryResult = $this->discoveryService->checkAfterExplore($character, $area, !$hasDepthTransition);
                    $developmentResult = $discoveryResult['development'] ?? null;
                    $newDiscoveries = array_merge($newDiscoveries, $discoveryResult['discoveries'] ?? []);
                    if ($developmentResult && ($developmentResult['gained'] ?? 0) > 0) {
                        $logText .= "<br><span class=\"text-emerald-700 font-bold\">【開拓】{$area->name}の開拓度 +{$developmentResult['gained']}（{$developmentResult['after']} / {$developmentResult['max']}）</span>";
                    }
                    foreach ($newDiscoveries as $discovery) {
                        $label = ($discovery['type'] ?? '') === 'city' ? '新しい街' : '新たな場所';
                        $logText .= "<br><span class=\"text-sky-700 font-extrabold\">【発見】{$label}「{$discovery['name']}」を発見した！</span>";
                    }

                    $valmonService = app(ValmonService::class);
                    $stateAfterVictory = $explorationStateService->currentFor($character);
                    $valmonEggFound = $valmonService->tryFindEgg($character, $area, $stateAfterVictory);
                    if ($valmonEggFound) {
                        $logText .= "<br><span class=\"text-pink-700 font-extrabold\">【ヴァルモンの卵】{$valmonEggFound['message']}</span>";
                    }

                    $valmonMaterialFind = $valmonService->tryPartnerFindMaterial($character, $area, $stateAfterVictory, $this->dropService, $targetEnemy);
                    if ($valmonMaterialFind) {
                        $logText .= "<br><span class=\"text-teal-700 font-extrabold\">【ヴァルモン】{$valmonMaterialFind['valmon_name']}が素材を見つけた！ {$valmonMaterialFind['material_name']} ×{$valmonMaterialFind['quantity']} を手に入れた。</span>";
                    }

                    $valmonDiscoveryHint = $valmonService->tryDiscoveryHint($character, $area);
                    if ($valmonDiscoveryHint) {
                        $logText .= "<br><span class=\"text-indigo-700 font-extrabold\">【ヴァルモン】{$valmonDiscoveryHint['valmon_name']}が何かの気配に気づいた。{$valmonDiscoveryHint['hint']}</span>";
                    }

                    $valmonRecovery = $valmonService->tryPartnerRecovery($character, $stateAfterVictory);
                    if ($valmonRecovery) {
                        $logText .= "<br><span class=\"text-emerald-700 font-extrabold\">【ヴァルモン】{$valmonRecovery['valmon_name']}が心配そうに寄り添った。<br>不思議な光に包まれ、HPが {$valmonRecovery['heal_amount']} 回復した！</span>";
                    }
                }

                if (($specialEvent['type'] ?? null) === 'dungeon_lord') {
                    $stateAfterDungeonLord = $explorationStateService->currentFor($character);
                    if ($stateAfterDungeonLord) {
                        $stateAfterDungeonLord->forceFill(['dungeon_lord_encountered' => true])->save();
                    }
                    $chainLootSummary = $explorationStateService->currentLootSummary($character, $areaId);
                    $logText .= "<br><span class=\"text-slate-700 font-bold\">【探索】ダンジョン主を退けた！探索度・危険度は維持され、この探索中にダンジョン主は再出現しません。</span>";
                } elseif (($specialEvent['type'] ?? null) === 'hidden_area_gate') {
                    $chainLootSummary = $explorationStateService->currentLootSummary($character, $areaId);
                    $logText .= "<br><span class=\"text-amber-700 font-bold\">【秘境帰還】秘境から戻っても、探索度・連戦・危険度は維持されます。</span>";
                } elseif (($specialEvent['type'] ?? null) === 'sub_area_gate') {
                    $chainLootSummary = $explorationStateService->currentLootSummary($character, $areaId);
                } else {
                    $chainLootSummary = $explorationStateService->currentLootSummary($character, $areaId);
                }
            }
        } else {
            $character->losses += 1;

            if (!$isBossBattle) {
                $adventureSupportService = app(AdventureSupportService::class);
                $preDefeatLootSummary = $explorationStateService->currentLootSummary($character, $areaId);
                $hasLootAtRisk = (int) ($preDefeatLootSummary['risk_total'] ?? 0) > 0;
                $emergencyRescueUsed = $hasLootAtRisk && $adventureSupportService->consumeEmergencyRescueIfAvailable($character);
                $insuranceEnabled = !$emergencyRescueUsed && $adventureSupportService->insuranceEnabled($character);
                $lossPercent = $emergencyRescueUsed ? 0 : ($insuranceEnabled ? 25 : 50);

                if ($emergencyRescueUsed) {
                    $rescueSupport = ['type' => 'emergency_rescue_request', 'loss_percent' => 0];
                    $materialPenalty = ['total_lost' => 0, 'materials' => [], 'items' => [], 'loss_percent' => 0];
                    $logText .= "<br><span class=\"text-sky-700 font-extrabold\">【緊急救助】冒険者協会へ緊急救助を要請しました。今回の入手品はすべて保護されました。探索は終了し、街へ帰還します。</span>";
                } else {
                    $rescueSupport = $insuranceEnabled ? ['type' => 'rescue_insurance', 'loss_percent' => 25] : null;
                    $materialPenalty = $explorationStateService->applyDefeatMaterialPenalty($character, $areaId, $lossPercent);
                }

                if ($insuranceEnabled) {
                    $logText .= "<br><span class=\"text-emerald-700 font-extrabold\">【救助保険】救助保険証の効果により、入手品ロストが25%に抑えられました。</span>";
                }

                if ($emergencyRescueUsed) {
                    $logText .= "<br><span class=\"text-sky-700 font-extrabold\">【ヴァルモンの卵】救助隊が卵を守ってくれた！ ヴァルモンの卵は無事だった。</span>";
                } else {
                    $valmonEggLost = app(ValmonService::class)->loseActiveEggs($character);
                    if (!empty($valmonEggLost)) {
                        $logText .= "<br><span class=\"text-red-700 font-extrabold\">【ヴァルモンの卵】探索中に見つけたヴァルモンの卵は失われてしまった……</span>";
                    }
                }

                if (($materialPenalty['total_lost'] ?? 0) > 0) {
                    $lostMaterialTexts = array_map(
                        fn (array $material) => $material['name'] . ' x' . $material['quantity'],
                        $materialPenalty['materials'] ?? []
                    );
                    $lostItemTexts = array_map(
                        fn (array $item) => $item['name'] . (!empty($item['rank']) ? ' ' . $item['rank'] : ''),
                        $materialPenalty['items'] ?? []
                    );
                    $lostTexts = array_values(array_filter(array_merge($lostMaterialTexts, $lostItemTexts)));
                    $logText .= "<br><span class=\"text-red-600 font-bold\">【戦利品喪失】探索中に集めた戦利品の一部を失った……（" . implode('、', $lostTexts) . "）</span>";
                }

                $goldLoss = app(GuildService::class)->calculateDefeatGoldLoss((int) ($character->money ?? 0));
                $goldLossAmount = (int) ($goldLoss['amount'] ?? 0);

                if ($goldLossAmount > 0) {
                    app(GoldService::class)->spend(
                        $character,
                        $goldLossAmount,
                        'exploration_defeat_gold_loss',
                        '探索敗北時に荷物を荒らされて失ったGold',
                        Area::class,
                        $areaId,
                        [
                            'area_id' => $areaId,
                            'danger_rate' => (int) ($state?->danger_rate ?? 0),
                            'rate' => (float) ($goldLoss['rate'] ?? 0),
                        ]
                    );

                    $logText .= "<br><span class=\"text-amber-700 font-extrabold\">【Gold喪失】倒れた隙に荷物を荒らされ、所持Goldの{$goldLoss['rate_label']}として " . number_format($goldLossAmount) . "G を失った。</span>";
                }

                $explorationStateService->reset($character, $areaId);
            }
            
            // 敗北時は最大HPの30%で復活
            $statusService = new \App\Services\CharacterStatusService();
            $finalStats = $statusService->getFinalStats($character);
            $character->current_hp = max(1, (int)(($finalStats['max_hp'] ?? $character->hp_base) * 0.3));
            $character->save();
        }

        if (!$isBossBattle) {
            $explorationSummary = $explorationStateService->summaryForArea($character, $area);
        }

        if (!$isBossBattle) {
            $materialHuntCompletion = $this->materialHuntCompletion($character, $areaId);
        }

        // 5. ログ保存
        $battleType = $isBossBattle ? 'boss' : 'normal';
        $battleLog = $this->battleLogService->addLog(
            $character,
            $areaId,
            $targetEnemy->id,
            $battleType,
            $battleResult->result === 'defeat' ? 'lose' : 'win',
            $expGained,
            $goldGained,
            $levelUpCount,
            $logText,
            $dropResult['item_id'] ?? null,
            $dropResult['character_item_id'] ?? null,
            $goldLossAmount ?? 0
        );
        if ($kisekiDrop && !empty($kisekiDrop['transaction_id'])) {
            $this->kisekiDropService->attachBattleLog((int) $kisekiDrop['transaction_id'], $battleLog->id);
        }

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
            'unlocked_areas' => $unlockedAreas,
            'drop' => $dropResult,
            'equipment_drops' => $equipmentDropResults,
            'material_drop' => $materialDropResult ?? [],
            'monster_mark_drop' => $monsterMarkDrop,
            'kiseki_drop' => $kisekiDrop,
            'drop_results' => $dropResults,
            'material_penalty' => $materialPenalty,
            'chain_loot_summary' => $chainLootSummary,
            'exploration_progress' => $explorationProgress,
            'exploration_summary' => $explorationSummary,
            'development' => $developmentResult,
            'new_discoveries' => $newDiscoveries,
            'special_event' => $specialEvent['type'] ?? null,
            'secret_realm_image' => $this->secretRealmImagePath($area),
            'secret_realm_name' => $specialEvent['secret_realm']['name'] ?? null,
            'gold_loss' => $goldLoss,
            'rescue_fee' => $goldLoss,
            'rescue_support' => $rescueSupport,
            'valmon_egg_found' => $valmonEggFound,
            'valmon_material_find' => $valmonMaterialFind,
            'valmon_discovery_hint' => $valmonDiscoveryHint,
            'valmon_recovery' => $valmonRecovery,
            'valmon_egg_lost' => $valmonEggLost,
            'material_hunt_completion' => $materialHuntCompletion,
        ];
    }

    private function materialHuntCompletion(Character $character, int $areaId): ?array
    {
        $hunt = session('material_hunt');
        if (!is_array($hunt)) {
            return null;
        }

        if ((int) ($hunt['source_area_id'] ?? 0) !== $areaId) {
            session()->forget('material_hunt');
            return null;
        }

        $materialId = (int) ($hunt['material_id'] ?? 0);
        $required = (int) ($hunt['required'] ?? 0);
        $startedOwned = (int) ($hunt['started_owned'] ?? 0);
        if ($materialId <= 0 || $required <= 0 || $startedOwned >= $required) {
            session()->forget('material_hunt');
            return null;
        }

        $owned = (int) (CharacterMaterial::where('character_id', $character->id)
            ->where('material_id', $materialId)
            ->value('quantity') ?? 0);

        if ($owned < $required) {
            return null;
        }

        session()->forget('material_hunt');

        return [
            'material_id' => $materialId,
            'material_name' => (string) ($hunt['material_name'] ?? '素材'),
            'required' => $required,
            'owned' => $owned,
            'gained_since_start' => max(0, $owned - $startedOwned),
        ];
    }

    private function secretRealmImagePath(Area $area): string
    {
        $cityId = max(1, min(10, (int) ($area->city_id ?? 1)));

        return sprintf('images/map/unexplored_region%02d.webp', $cityId);
    }

    private function depthRewardBonus(Character $character, Area $area, $state): array
    {
        if (!$state) {
            return ['multiplier' => 1.0, 'label' => '表層'];
        }

        $depthService = app(ExplorationDepthService::class);
        $tier = $depthService->activeTierFor(
            $character,
            $area,
            (int) ($state->exploration_point ?? 0),
            (int) ($state->danger_rate ?? 0)
        );

        return [
            'multiplier' => $depthService->expRewardMultiplierForTier($tier),
            'label' => $tier['label'] ?? '表層',
        ];
    }

    private function rollSpecialEvent(Character $character, Area $area, Enemy $baseEnemy, $state): ?array
    {
        $point = (int) ($state->exploration_point ?? 0);
        $secretRealmService = app(SecretRealmService::class);
        $stateService = app(ExplorationStateService::class);
        $gateRateMultiplier = max(0, min(5, app(GameSettingService::class)->getFloat('secret_realm.gate_rate_multiplier', 1.0)));
        $baseGateRate = min(20.0, $secretRealmService->gateRate($point) * $gateRateMultiplier);
        $gateRate = $stateService->secretRealmRate($state, $baseGateRate);

        if ($gateRate > 0 && $this->rollPercent($gateRate)) {
            $realm = $secretRealmService->realmForArea($area);
            $stateService->markSecretRealmFound($character, $area->id);
            if ($this->rollPercent(20.0)) {
                return [
                    'type' => 'secret_realm_lord',
                    'enemy' => $secretRealmService->makeSecretRealmLord($area, $baseEnemy, $realm),
                    'secret_realm' => $realm,
                ];
            }

            return [
                'type' => 'hidden_area_gate',
                'enemy' => $this->makeEventMarkerEnemy($area, $baseEnemy, '秘境への入口'),
                'source_enemy' => $baseEnemy,
                'secret_realm' => $realm,
            ];
        }

        $subAreaRoute = app(SubAreaDiscoveryService::class)->rollDiscovery($character, $area, $state);
        if ($subAreaRoute) {
            return [
                'type' => 'sub_area_gate',
                'enemy' => $this->makeEventMarkerEnemy($area, $baseEnemy, '未知の入口'),
                'source_enemy' => $baseEnemy,
                'sub_area_route' => $subAreaRoute,
            ];
        }

        if ($point >= 300 && !$state->dungeon_lord_encountered && $this->rollPercent(2.0)) {
            $state->forceFill(['dungeon_lord_encountered' => true])->save();
            return [
                'type' => 'dungeon_lord_encounter',
                'enemy' => $this->makeDungeonLordEnemy($area, $baseEnemy),
            ];
        }

        if ($point >= 200 && $this->rollPercent(3.0)) {
            return [
                'type' => 'golden_goblin',
                'enemy' => $this->makeGoldenGoblinEnemy($area, $baseEnemy),
            ];
        }

        $treasureRate = app(ExplorationStateService::class)->treasureRate($state, $point);
        if ($treasureRate > 0 && $this->rollPercent($treasureRate)) {
            return [
                'type' => 'treasure',
                'enemy' => $this->makeEventMarkerEnemy($area, $baseEnemy, '輝く宝箱'),
                'source_enemy' => $baseEnemy,
            ];
        }

        return null;
    }

    private function resolveNonCombatEvent(Character $character, Area $area, Enemy $baseEnemy, string $type, ?array $event = null): BattleResult
    {
        $result = new BattleResult();
        $result->result = 'victory';
        $result->playerHpAfter = (int) $character->current_hp;
        $result->playerMpAfter = (int) ($character->current_mp ?? 0);

        if ($type === 'hidden_area_gate') {
            $result->logs[] = "【探索】深い霧の向こうに、秘境への入口を発見した！";
            $gatherResult = app(SecretRealmService::class)->gather($character, $area, $baseEnemy, $this->dropService);
            foreach ($gatherResult['logs'] ?? [] as $gatherLog) {
                $result->logs[] = "<span class=\"text-emerald-700 font-bold\">{$gatherLog}</span>";
            }
            foreach ($gatherResult['drops'] ?? [] as $drop) {
                $result->drops[] = $drop;
            }
            if (empty($gatherResult['drops'])) {
                $result->logs[] = "<span class=\"text-slate-700 font-bold\">入口は不安定で、今回は奥の素材を持ち帰れなかった。</span>";
            }

            return $result;
        }

        if ($type === 'sub_area_gate') {
            $route = $event['sub_area_route'] ?? null;
            if ($route) {
                $state = app(ExplorationStateService::class)->getOrStart($character, $area->id);
                $discovery = app(SubAreaDiscoveryService::class)->recordDiscovery($character, $route, $state);
                $subArea = $discovery['sub_area'] ?? null;
                $routeName = $route->route_name ?? '未知の入口';
                $entrance = $route->entrance_description ?: '奥へ続く道を見つけた。';

                $result->logs[] = "【探索】{$entrance}";
                $result->logs[] = "<span class=\"text-indigo-800 font-extrabold\">{$discovery['message']}</span>";
                $result->logs[] = "<span class=\"text-slate-700 font-bold\">入口名: {$routeName}</span>";
                if ($subArea?->recommended_level_min) {
                    $powerService = app(CharacterPowerService::class);
                    $powerRange = $powerService->recommendedRangeForLevels(
                        (int) ($subArea->recommended_level_min ?? 1),
                        (int) ($subArea->recommended_level_max ?? $subArea->recommended_level_min ?? 1)
                    );
                    $result->logs[] = '<span class="text-amber-700 font-bold">目安戦力 '
                        . $powerService->formatRange($powerRange)
                        . '。いまは地図に記録しました。</span>';
                }

                return $result;
            }

            $result->logs[] = "【探索】奥へ続く道を見つけたが、霧に包まれて見失ってしまった。";

            return $result;
        }

        if ($type === 'dungeon_lord_encounter') {
            $result->result = 'event';
            $result->logs[] = "【探索】周囲の空気が変わった……。";
            $result->logs[] = "<span class=\"text-red-800 font-bold\">このダンジョンを支配する強敵が姿を現した！</span>";
            $result->logs[] = "挑むか、今は退いて探索を続けるかを選べます。";

            return $result;
        }

        app(ExplorationStateService::class)->markTreasureFound($character, $area->id);

        $result->logs[] = "【探索】輝く宝箱を発見した！";

        $drops = [];
        $quantity = rand(2, 4);
        for ($i = 0; $i < $quantity; $i++) {
            $material = $this->treasureMaterial($area, $baseEnemy);
            if (!$material) {
                continue;
            }

            $drop = $this->dropService->grantMaterialReward($character, $material, 'treasure', $baseEnemy);
            $result->drops[] = $drop;
            $drops[] = $drop['name'];
        }

        $valuable = $this->treasureValuableMaterial();
        if ($valuable) {
            $drop = $this->dropService->grantMaterialReward($character, $valuable, 'treasure_valuable', $baseEnemy);
            $result->drops[] = $drop;
            $drops[] = $drop['name'];
            $result->logs[] = "<span class=\"text-amber-700 font-extrabold\">【換金品】宝箱の底に {$drop['name']} が入っていた！ 倉庫で売却できます。</span>";
        }

        if (!empty($drops)) {
            $summary = collect($drops)
                ->countBy()
                ->map(fn (int $count, string $name): string => $name . ' x' . $count)
                ->values()
                ->implode('、');
            $result->logs[] = "<span class=\"text-emerald-700 font-bold\">宝箱から {$summary} を見つけた。</span>";
            return $result;
        }

        $result->logs[] = "<span class=\"text-slate-700 font-bold\">宝箱は古びていて、今回は使える素材が残っていなかった。</span>";

        return $result;
    }

    private function makeGoldenGoblinEnemy(Area $area, Enemy $baseEnemy): Enemy
    {
        $enemy = $baseEnemy->replicate();
        $enemy->id = $baseEnemy->id;
        $enemy->exists = true;
        $enemy->setRelation('area', $area);
        $enemy->name = '黄金ゴブリン';
        $enemy->role = '特殊';
        $enemy->type_name = '高速型';
        $enemy->max_hp = max(1, (int) floor((int) $baseEnemy->max_hp * 0.6));
        $enemy->str = max(1, (int) floor((int) $baseEnemy->str * 0.7));
        $enemy->def = max(1, (int) floor((int) $baseEnemy->def * 0.7));
        $enemy->mag = max(1, (int) floor((int) ($baseEnemy->mag ?? $baseEnemy->str) * 0.7));
        $enemy->spr = max(1, (int) floor((int) ($baseEnemy->spr ?? $baseEnemy->def) * 0.7));
        $enemy->agi = max(1, (int) floor((int) $baseEnemy->agi * 1.5));
        $enemy->exp_reward = max(1, (int) floor((int) $baseEnemy->exp_reward * 0.5));
        $enemy->gold_reward = 0;
        $enemy->job_exp_reward = 0;
        $enemy->is_boss = false;

        return $enemy;
    }

    private function goldenGoblinGoldReward(Area $area): int
    {
        $averageGold = (float) Enemy::where('area_id', $area->id)
            ->where('is_boss', false)
            ->avg('gold_reward');
        $baseGold = max(5, (int) round($averageGold));
        $multiplier = random_int(
            (int) round(self::GOLDEN_GOBLIN_REWARD_MIN_MULTIPLIER * 100),
            (int) round(self::GOLDEN_GOBLIN_REWARD_MAX_MULTIPLIER * 100)
        ) / 100;

        return max(1, (int) floor($baseGold * $multiplier));
    }

    private function makeEventMarkerEnemy(Area $area, Enemy $baseEnemy, string $name): Enemy
    {
        $enemy = $baseEnemy->replicate();
        $enemy->id = $baseEnemy->id;
        $enemy->exists = true;
        $enemy->setRelation('area', $area);
        $enemy->name = $name;
        $enemy->role = '探索イベント';
        $enemy->type_name = '特殊';
        $enemy->max_hp = 1;
        $enemy->str = 0;
        $enemy->def = 0;
        $enemy->agi = 0;
        $enemy->mag = 0;
        $enemy->spr = 0;
        $enemy->luk = 0;
        $enemy->exp_reward = 0;
        $enemy->gold_reward = 0;
        $enemy->job_exp_reward = 0;
        $enemy->is_boss = false;

        return $enemy;
    }

    private function makeDungeonLordEnemy(Area $area, Enemy $baseEnemy): Enemy
    {
        $boss = Enemy::where('area_id', $area->id)->where('is_boss', true)->first() ?: $baseEnemy;
        $enemy = $boss->replicate();
        $enemy->id = $boss->id;
        $enemy->exists = true;
        $enemy->setRelation('area', $area);
        $enemy->name = $area->name . 'のダンジョン主';
        $enemy->role = 'ダンジョン主';
        $enemy->type_name = $boss->type_name ?? '標準型';
        $enemy->max_hp = max(1, (int) floor((int) $boss->max_hp * 0.95));
        $enemy->str = max(1, (int) floor((int) $boss->str * 0.95));
        $enemy->def = max(1, (int) floor((int) $boss->def * 0.95));
        $enemy->mag = max(1, (int) floor((int) ($boss->mag ?? $boss->str) * 0.95));
        $enemy->spr = max(1, (int) floor((int) ($boss->spr ?? $boss->def) * 0.95));
        $enemy->agi = max(1, (int) floor((int) $boss->agi * 0.95));
        $enemy->exp_reward = max((int) $baseEnemy->exp_reward * 3, (int) floor((int) $boss->exp_reward * 0.4));
        $enemy->gold_reward = max((int) ($baseEnemy->gold_reward ?? 0) * 5, (int) floor((int) ($boss->gold_reward ?? 0) * 0.4));
        $enemy->job_exp_reward = max(3, (int) ($boss->job_exp_reward ?? 0));
        $enemy->is_boss = false;

        return $enemy;
    }

    private function treasureMaterial(Area $area, Enemy $baseEnemy): ?Material
    {
        $materials = MaterialDrop::whereHas('enemy', fn ($query) => $query->where('area_id', $area->id))
            ->where('is_active', true)
            ->where('drop_first_clear_only', false)
            ->where('drop_rate', '>', 0)
            ->with('material')
            ->get()
            ->pluck('material')
            ->filter()
            ->map(fn (Material $material) => $this->normalizeTreasureMaterial($material))
            ->filter()
            ->reject(fn (Material $material) => (string) ($material->material_type ?? '') === 'sell_treasure')
            ->unique('id')
            ->values();

        return $materials->isNotEmpty() ? $materials->random() : null;
    }

    private function treasureValuableMaterial(): ?Material
    {
        if (!$this->rollPercent(70.0)) {
            return null;
        }

        $weights = [
            'MAT_TREASURE_CHIPPED_MAGIC_STONE' => 450,
            'MAT_TREASURE_MONSTER_FANG' => 300,
            'MAT_TREASURE_OLD_SILVER_COIN' => 160,
            'MAT_TREASURE_SPIRIT_FEATHER' => 70,
            'MAT_TREASURE_DRAGON_SCALE' => 20,
            'MAT_TREASURE_BEAST_HORN' => 7,
            'MAT_TREASURE_ANCIENT_GOLD_COIN' => 2,
        ];

        $materials = Material::whereIn('material_code', array_keys($weights))
            ->get()
            ->keyBy('material_code');
        if ($materials->isEmpty()) {
            return null;
        }

        $total = collect($weights)
            ->filter(fn (int $weight, string $code): bool => $materials->has($code))
            ->sum();
        if ($total <= 0) {
            return null;
        }

        $roll = rand(1, $total);
        $current = 0;
        foreach ($weights as $code => $weight) {
            if (!$materials->has($code)) {
                continue;
            }

            $current += $weight;
            if ($roll <= $current) {
                return $materials[$code];
            }
        }

        return null;
    }

    private function normalizeTreasureMaterial(Material $material): ?Material
    {
        $code = (string) $material->material_code;
        if (!in_array($code, self::LEGACY_COMMON_FRAGMENT_CODES, true)) {
            return $material;
        }

        return Material::where('material_code', self::COMMON_MONSTER_FRAGMENT_CODE)->first();
    }

    private function rollPercent(float $rate): bool
    {
        return $rate > 0 && rand(1, 10000) <= $rate * 100;
    }
}
