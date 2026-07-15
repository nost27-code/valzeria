<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterMaterial;
use App\Models\Enemy;
use App\Models\Item;
use App\Models\Material;
use App\Models\MaterialDrop;
use App\Services\Battle\BattleResult;
use App\Support\CharacterIconCatalog;

class ExplorationService
{
    private const COMMON_MONSTER_FRAGMENT_CODE = 'MAT_COMMON_MONSTER_FRAGMENT';
    private const LEGACY_COMMON_FRAGMENT_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];
    private const GOLDEN_GOBLIN_REWARD_MIN_MULTIPLIER = 2.0;
    private const GOLDEN_GOBLIN_REWARD_MAX_MULTIPLIER = 3.0;
    private const PLAYER_ENCOUNTER_CHANCE_PERCENT = 0.1;
    private const TREASURE_ANCIENT_DROP_CHANCE_PERCENT = 1.0;
    private const FERDIA_AREA_ID_MIN = 1001;
    private const FERDIA_AREA_ID_MAX = 1013;
    private const PLAYER_ENCOUNTER_GIFT_ITEM_NAME = '薬草';

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
        $staminaService = app(ExplorationStaminaService::class);
        $consumesStamina = $staminaService->enabled();
        $staminaSummary = $consumesStamina ? $staminaService->summary($character) : null;
        $consumedStamina = 0;
        $staminaUpdatedAtBeforeConsume = null;
        $staminaMaxUp = 0;

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
        if (!$consumesStamina && !$skipBattleCooldown && !$isBossBattle && $forcedEvent !== 'dungeon_lord' && $battleCooldownSeconds > 0 && $character->last_battle_at) {
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

        if ($consumesStamina) {
            $consumeResult = $staminaService->consumeForExplore($character);
            $staminaSummary = $consumeResult['stamina'] ?? $staminaService->summary($character);
            if (!($consumeResult['ok'] ?? false)) {
                return [
                    'error' => $consumeResult['error'] ?? '探索力が足りません。回復を待ってください。',
                    'exploration_stamina' => $staminaSummary,
                ];
            }

            $consumedStamina = (int) ($consumeResult['consumed'] ?? 0);
            $staminaUpdatedAtBeforeConsume = $consumeResult['stamina_updated_at_before_consume'] ?? null;
        }

        $lastBattleAtBefore = $character->last_battle_at?->copy();
        $character->last_battle_at = now();
        $character->save();
        $state = !$isBossBattle ? $explorationStateService->getOrStart($character, $areaId) : null;

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

        if ($consumesStamina && ($specialEvent['type'] ?? null) === 'dungeon_lord_encounter') {
            $refundResult = $staminaService->refundForExplore($character, $consumedStamina, $staminaUpdatedAtBeforeConsume);
            $staminaSummary = $refundResult['stamina'] ?? $staminaService->summary($character);
            $character->last_battle_at = $lastBattleAtBefore;
            $character->save();
        }

        $expGained = 0;
        $goldGained = 0;
        $jobExpGained = 0;
        $levelUpCount = 0;
        $levelUpDetails = [];
        $progression = null;
        $unlockedAreas = [];
        $dropResult = null;
        $dropResults = ['materials' => [], 'equipment' => [], 'by_slot' => []];
        $materialDropResult = [];
        $equipmentDropResults = [];
        $monsterMarkDrop = null;
        $secretRealmRewards = [];
        $kisekiDrop = null;
        $crownProofAwarded = false;
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
        $playerEncounter = null;
        $areaClearStorageReward = null;

        // 4. 勝敗に応じた処理
        if ($isEventOnly) {
            if (!$isBossBattle) {
                $chainLootSummary = $explorationStateService->currentLootSummary($character, $areaId);
            }
        } elseif ($isWin) {
            $staminaMaxBefore = $staminaService->maxForCharacter($character);
            $character->wins += 1;
            $staminaMaxUp = $staminaService->maxForCharacter($character) - $staminaMaxBefore;
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
            $progression = $rewardResult['progression'] ?? null;

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
                $hadCrownProof = app(JobService::class)->hasCrownProof($character);
                $areaClearRewards = [];
                $unlockedAreas = $this->areaService->unlockNextArea($character, $areaId, $areaClearRewards);
                $crownProofAwarded = !$hadCrownProof
                    && (int) $areaId === JobService::CROWN_PROOF_AREA_ID
                    && app(JobService::class)->hasCrownProof($character);
                if ($crownProofAwarded) {
                    $logText .= '<br><span class="text-rose-700 font-bold">【討伐の証】冠位の証が刻まれた！ 冠位職への道が開かれた。</span>';
                }
                $areaClearStorageReward = $areaClearRewards['storage'] ?? null;
                if ($areaClearStorageReward) {
                    $logText .= '<br><span class="text-emerald-700 font-bold">【街踏破報酬】素材倉庫の保管枠が+'
                        . number_format((int) $areaClearStorageReward['material_bonus'])
                        . '、装備倉庫の保管枠が+'
                        . number_format((int) $areaClearStorageReward['equipment_bonus'])
                        . 'されました。</span>';
                }

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
                $dropResults = $this->mergeDropResults(
                    $dropResults,
                    $this->dropService->rollBattleDrops(
                        $character,
                        $targetEnemy,
                        $battleResult->dropBonusPercent,
                        $battleResult->rareBonusPercent,
                        rollMonsterMark: true
                    )
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
                if (($specialEvent['type'] ?? null) === 'treasure') {
                    $treasureMaterials = collect($materialDropResult)
                        ->reject(fn (array $drop): bool => ($drop['kind'] ?? null) === 'treasure_valuable')
                        ->pluck('name')
                        ->filter()
                        ->countBy()
                        ->map(fn (int $count, string $name): string => $name . ($count > 1 ? ' x' . $count : ''))
                        ->values()
                        ->implode('、');

                    if ($treasureMaterials !== '') {
                        $logText .= "<br><span class=\"text-emerald-700 font-bold\">【宝箱の中身】{$treasureMaterials} を手に入れた！</span>";
                    }
                } else {
                    $materialNames = array_column($materialDropResult, 'name');
                    $logText .= "<br><span class=\"text-green-600 font-bold\">【素材獲得】" . implode('、', $materialNames) . " を手に入れた！</span>";
                }
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
                        foreach (($developmentResult['events'] ?? []) as $event) {
                            $eventText = e((string) ($event['text'] ?? ''));
                            if ($eventText !== '') {
                                $logText .= "<br><span class=\"text-amber-700 font-bold\">{$eventText}</span>";
                            }
                        }
                    }

                    $playerEncounter = $this->rollPlayerEncounter($character, $area);
                    if ($playerEncounter) {
                        $logText .= "<br><span class=\"text-cyan-700 font-extrabold\">【出会い】" . e($playerEncounter['message']) . '</span>';
                        if (!empty($playerEncounter['gift']['name'])) {
                            $logText .= "<br><span class=\"text-emerald-700 font-bold\">【出会いのお礼】{$playerEncounter['gift']['name']} x{$playerEncounter['gift']['quantity']} を受け取った。</span>";
                        }
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
            $jobExpGained,
            $levelUpCount,
            $logText,
            $dropResult['item_id'] ?? null,
            $dropResult['character_item_id'] ?? null,
            $goldLossAmount ?? 0,
            $this->battleLogService->telemetryFor($character, $battleResult)
        );
        if ($kisekiDrop && !empty($kisekiDrop['transaction_id'])) {
            $this->kisekiDropService->attachBattleLog((int) $kisekiDrop['transaction_id'], $battleLog->id);
        }

        $supportResult = app(ExplorationSupportService::class)->completeBattle(
            $character,
            $battleLog,
            $battleResult->explorationSupportSnapshot ?? null,
        );
        foreach ($supportResult['logs'] ?? [] as $supportLog) {
            $logText .= '<br>' . $supportLog;
        }
        if (($supportResult['logs'] ?? []) !== []) {
            $battleLog->forceFill(['log_text' => $logText])->save();
        }

        return [
            'success' => true,
            'result' => $battleResult->result,
            'turn_count' => $battleResult->turnCount,
            'log' => $logText,
            'enemy' => $targetEnemy,
            'exp_gained' => $expGained,
            'gold_gained' => $goldGained,
            'job_exp_gained' => $jobExpGained,
            'progression' => $progression,
            'enemy_stat_display' => $battleResult->enemyStatDisplay ?? [],
            'level_up_count' => $levelUpCount,
            'level_up_details' => $levelUpDetails,
            'unlocked_areas' => $unlockedAreas,
            'drop' => $dropResult,
            'equipment_drops' => $equipmentDropResults,
            'material_drop' => $this->summarizeMaterialDrops($materialDropResult ?? []),
            'monster_mark_drop' => $monsterMarkDrop,
            'kiseki_drop' => $kisekiDrop,
            'crown_proof_awarded' => $crownProofAwarded,
            'drop_results' => $dropResults,
            'exploration_support' => $supportResult['active'] ?? app(ExplorationSupportService::class)->payload($character),
            'material_penalty' => $materialPenalty,
            'chain_loot_summary' => $chainLootSummary,
            'exploration_progress' => $explorationProgress,
            'exploration_summary' => $explorationSummary,
            'development' => $developmentResult,
            'story_record' => app(FerdiaMapService::class)->storyRecordForArea($character, $area),
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
            'exploration_stamina' => $consumesStamina ? $staminaService->summary($character) : $staminaSummary,
            'stamina_max_up' => $staminaMaxUp,
            'valmon_recovery' => $valmonRecovery,
            'valmon_egg_lost' => $valmonEggLost,
            'material_hunt_completion' => $materialHuntCompletion,
            'player_encounter' => $playerEncounter,
            'area_clear_storage_reward' => $areaClearStorageReward,
            'sub_area_name' => $battleResult->eventData['sub_area_name'] ?? null,
            'sub_area_route_name' => $battleResult->eventData['sub_area_route_name'] ?? null,
            'sub_area_discovery_id' => $battleResult->eventData['sub_area_discovery_id'] ?? null,
        ];
    }

    public function exploreRepeated(Character $character, int $areaId, int $requestedCount = 10): array
    {
        $requestedCount = max(2, min(10, $requestedCount));
        $staminaService = app(ExplorationStaminaService::class);
        if (!$staminaService->enabled()) {
            return [
                'error' => '10回探索は探索力制が有効な時だけ使えます。',
                'exploration_stamina' => $staminaService->summary($character),
            ];
        }

        $totalExp = 0;
        $totalGold = 0;
        $totalJobExp = 0;
        $totalKiseki = 0;
        $totalStaminaMaxUp = 0;
        $completedCount = 0;
        $levelUpDetails = [];
        $materialDrops = [];
        $equipmentDrops = [];
        $monsterMarkDrops = [];
        $newDiscoveries = [];
        $runs = [];
        $lastResult = null;
        $stopReason = null;

        for ($i = 1; $i <= $requestedCount; $i++) {
            $character->refresh();
            $staminaSummary = $staminaService->summary($character);
            if ((int) ($staminaSummary['current'] ?? 0) < (int) ($staminaSummary['cost'] ?? 1)) {
                $stopReason = 'stamina_empty';
                break;
            }

            $finalStats = app(CharacterStatusService::class)->getFinalStats($character);
            $maxHp = max(1, (int) ($finalStats['max_hp'] ?? $character->hp_base ?? 1));
            $pinchHp = max(1, (int) floor($maxHp * 0.3));
            if ((int) $character->current_hp <= $pinchHp) {
                $stopReason = 'hp_pinch';
                break;
            }

            $result = $this->explore($character, $areaId, false, null, true);
            $lastResult = $result;

            if (isset($result['error'])) {
                $stopReason = 'error';
                break;
            }

            $completedCount++;
            $exp = (int) ($result['exp_gained'] ?? 0);
            $gold = (int) ($result['gold_gained'] ?? 0);
            $jobExp = (int) ($result['job_exp_gained'] ?? 0);
            $totalExp += $exp;
            $totalGold += $gold;
            $totalJobExp += $jobExp;
            $totalStaminaMaxUp += (int) ($result['stamina_max_up'] ?? 0);
            $levelUpDetails = array_merge($levelUpDetails, $result['level_up_details'] ?? []);
            $materialDrops = array_merge($materialDrops, $result['material_drop'] ?? []);
            $equipmentDrops = array_merge($equipmentDrops, $result['equipment_drops'] ?? []);
            $newDiscoveries = array_merge($newDiscoveries, $result['new_discoveries'] ?? []);

            if (!empty($result['monster_mark_drop']) && is_array($result['monster_mark_drop'])) {
                $monsterMarkDrop = $result['monster_mark_drop'];
                $monsterMarkDrops[] = [
                    'index' => $i,
                    'name' => (string) ($monsterMarkDrop['name'] ?? '印'),
                    'total_quantity' => (int) ($monsterMarkDrop['total_quantity'] ?? 0),
                    'level_up' => (bool) ($monsterMarkDrop['level_up'] ?? false),
                    'unlocked_level' => $monsterMarkDrop['unlocked_level'] ?? null,
                    'bonus_stat_label' => (string) ($monsterMarkDrop['bonus_stat_label'] ?? ''),
                    'total_bonus' => (int) ($monsterMarkDrop['total_bonus'] ?? 0),
                ];
            }

            if (!empty($result['kiseki_drop'])) {
                $totalKiseki += (int) ($result['kiseki_drop']['amount'] ?? 1);
            }

            $playerEncounter = is_array($result['player_encounter'] ?? null)
                ? $result['player_encounter']
                : null;
            $enemyName = is_object($result['enemy'] ?? null)
                ? (string) (($result['enemy']->name ?? '敵'))
                : (string) (($result['enemy']['name'] ?? '敵'));
            $runs[] = [
                'index' => $i,
                'enemy_name' => $enemyName,
                'result' => $result['result'] ?? 'unknown',
                'exp' => $exp,
                'gold' => $gold,
                'job_exp' => $jobExp,
                'monster_mark' => $result['monster_mark_drop']['name'] ?? null,
                'special_event' => $result['special_event'] ?? null,
                'player_encounter' => $playerEncounter,
            ];

            $specialEventType = $result['special_event'] ?? null;
            if ($specialEventType === 'secret_realm_lord') {
                $stopReason = in_array($result['result'] ?? null, ['victory', 'win'], true)
                    ? 'secret_realm_lord_victory'
                    : (($result['result'] ?? null) === 'timeout' ? 'timeout' : 'defeat');
                break;
            }

            if ($specialEventType !== null && !in_array($specialEventType, ['treasure', 'golden_goblin'], true)) {
                $stopReason = match ($specialEventType) {
                    'dungeon_lord_encounter' => 'dungeon_lord_encounter',
                    'hidden_area_gate' => 'hidden_area_gate',
                    'sub_area_gate' => 'sub_area_gate',
                    default => 'special_event',
                };
                break;
            }

            if (!empty($result['exploration_progress']['depth_transitions'] ?? [])) {
                $stopReason = 'depth_transition';
                break;
            }

            if (!in_array($result['result'] ?? null, ['victory', 'win'], true)) {
                $stopReason = ($result['result'] ?? null) === 'timeout' ? 'timeout' : 'defeat';
                break;
            }
        }

        if (!$lastResult) {
            return [
                'error' => match ($stopReason) {
                    'hp_pinch' => 'HPが少なくなっています。宿屋や回復アイテムで整えてから探索してください。',
                    'stamina_empty' => '探索力が足りません。回復を待つか、探索力回復アイテムを使ってください。',
                    default => '10回探索を開始できませんでした。',
                },
                'exploration_stamina' => $staminaService->summary($character),
                'batch_explore' => [
                    'requested' => $requestedCount,
                    'completed' => 0,
                    'stop_reason' => $stopReason,
                ],
            ];
        }

        $summaryLines = [
            '<span class="text-sky-800 font-extrabold">【10回探索】最大' . $requestedCount . '回の連続探索を行いました。</span>',
        ];
        foreach ($runs as $run) {
            $isTreasureRun = ($run['special_event'] ?? null) === 'treasure';
            $isGoldenGoblinRun = ($run['special_event'] ?? null) === 'golden_goblin';
            $isDungeonLordEncounterRun = ($run['special_event'] ?? null) === 'dungeon_lord_encounter';
            $isSecretRealmLordRun = ($run['special_event'] ?? null) === 'secret_realm_lord';
            $isSecretRealmLordVictoryRun = $isSecretRealmLordRun && in_array($run['result'] ?? null, ['victory', 'win'], true);
            $isHiddenGateRun = ($run['special_event'] ?? null) === 'hidden_area_gate';
            $isSubAreaGateRun = ($run['special_event'] ?? null) === 'sub_area_gate';
            $summaryLines[] = sprintf(
                $isDungeonLordEncounterRun
                    ? '<span class="font-bold" style="color:#991b1b;background:#fee2e2;padding:1px 4px;border-radius:4px;">%d回目: %s遭遇</span>'
                    : ($isSecretRealmLordRun
                    ? '<span class="font-bold" style="color:#6d28d9;background:#f3e8ff;padding:1px 4px;border-radius:4px;">%d回目: %s' . ($isSecretRealmLordVictoryRun ? '撃破' : '戦') . ' / EXP +%s / Job EXP +%s / Gold +%sG</span>'
                    : ($isHiddenGateRun
                    ? '<span class="font-bold" style="color:#047857;background:#ecfdf5;padding:1px 4px;border-radius:4px;">%d回目: %s発見 / EXP +%s / Job EXP +%s / Gold +%sG</span>'
                    : ($isSubAreaGateRun
                    ? '<span class="font-bold" style="color:#4338ca;background:#eef2ff;padding:1px 4px;border-radius:4px;">%d回目: %s発見 / EXP +%s / Job EXP +%s / Gold +%sG</span>'
                    : ($isGoldenGoblinRun
                    ? '<span class="font-bold" style="color:#b45309;background:#fef3c7;padding:1px 4px;border-radius:4px;">%d回目: %s / EXP +%s / Job EXP +%s / Gold +%sG</span>'
                    : ($isTreasureRun
                    ? '<span class="font-bold" style="color:#b45309;background:#fef9c3;padding:1px 4px;border-radius:4px;">%d回目: %s / EXP +%s / Job EXP +%s / Gold +%sG</span>'
                    : '<span class="text-slate-700 font-bold">%d回目: %s / EXP +%s / Job EXP +%s / Gold +%sG</span>'))))),
                (int) $run['index'],
                e($run['enemy_name']),
                number_format((int) $run['exp']),
                number_format((int) $run['job_exp']),
                number_format((int) $run['gold'])
            );

            if (!empty($run['player_encounter']['message'])) {
                $giftText = !empty($run['player_encounter']['gift']['name'])
                    ? ' / ' . e((string) $run['player_encounter']['gift']['name']) . ' x' . number_format((int) ($run['player_encounter']['gift']['quantity'] ?? 1))
                    : '';
                $summaryLines[] = '<span class="font-bold" style="color:#0e7490;background:#ecfeff;padding:1px 4px;border-radius:4px;">'
                    . (int) $run['index'] . '回目: 出会い / '
                    . e((string) $run['player_encounter']['message'])
                    . $giftText
                    . '</span>';
            }
        }

        $stoppedRun = collect($runs)->last();
        $stoppedRunIndex = (int) ($stoppedRun['index'] ?? $completedCount);
        $stoppedEnemyName = (string) ($stoppedRun['enemy_name'] ?? '敵');
        $depthTransition = $this->firstDepthTransition($lastResult);
        $depthTransitionLabel = (string) ($depthTransition['label'] ?? '次の深度');

        $stopText = match ($stopReason) {
            'hp_pinch' => 'HPが少なくなったため、途中で探索を止めました。',
            'defeat' => "{$stoppedRunIndex}回目の{$stoppedEnemyName}戦で敗北したため、途中で探索を止めました。HPは敗北後に最大HPの30%まで回復した状態です。",
            'timeout' => "{$stoppedRunIndex}回目の{$stoppedEnemyName}戦が長引いたため、途中で探索を止めました。",
            'dungeon_lord_encounter' => 'ダンジョン主と遭遇したため、連続探索を止めました。',
            'secret_realm_lord_victory' => "{$stoppedRunIndex}回目に{$stoppedEnemyName}を撃破したため、連続探索を止めました。秘境主の報酬を獲得しています。",
            'hidden_area_gate' => "{$stoppedRunIndex}回目に秘境への入口を発見したため、連続探索を止めました。秘境の採取結果を確認してください。",
            'sub_area_gate' => "{$stoppedRunIndex}回目に未知の入口を発見したため、連続探索を止めました。発見した場所は記録されています。",
            'special_event' => '特殊な出来事が起きたため、途中で探索を止めました。',
            'depth_transition' => "新しい探索深度への入口に到達したため、途中で探索を止めました。深度はまだ切り替わっていません。下の「{$depthTransitionLabel}へ進む」で次の深度へ進みます。",
            'stamina_empty' => '探索力が尽きたため、途中で探索を止めました。回復後にまた探索できます。',
            'error' => '探索を続けられない状態になったため、途中で止めました。',
            default => '',
        };
        $defeatLossSummary = $stopReason === 'defeat'
            ? $this->batchDefeatLossSummary($lastResult)
            : null;
        if ($stopText !== '') {
            $summaryLines[] = '<span class="text-amber-700 font-extrabold">【停止理由】' . $stopText . '</span>';
        }

        $lastResult['log'] = implode('<br>', $summaryLines);
        $lastResult['exp_gained'] = $totalExp;
        $lastResult['gold_gained'] = $totalGold;
        $lastResult['job_exp_gained'] = $totalJobExp;
        $lastResult['level_up_count'] = count($levelUpDetails);
        $lastResult['level_up_details'] = $levelUpDetails;
        $lastResult['material_drop'] = $this->summarizeMaterialDrops($materialDrops);
        $lastResult['equipment_drops'] = $equipmentDrops;
        $lastResult['drop'] = $equipmentDrops[0] ?? null;
        $lastResult['new_discoveries'] = $newDiscoveries;
        $lastResult['stamina_max_up'] = $totalStaminaMaxUp;
        if ($totalKiseki > 0) {
            $lastResult['kiseki_drop'] = ['amount' => $totalKiseki];
        }
        $lastResult['exploration_stamina'] = $staminaService->summary($character);
        $lastResult['batch_explore'] = [
            'requested' => $requestedCount,
            'completed' => $completedCount,
            'stop_reason' => $stopReason,
            'stop_text' => $stopText,
            'total_exp' => $totalExp,
            'total_gold' => $totalGold,
            'total_job_exp' => $totalJobExp,
            'total_kiseki' => $totalKiseki,
            'defeat_loss' => $defeatLossSummary,
            'monster_mark_drops' => $monsterMarkDrops,
            'runs' => $runs,
        ];

        if ($stopReason === 'depth_transition') {
            $depthGate = $this->currentDepthGateForBatch($character, $areaId, $depthTransition);
            if ($depthGate) {
                $lastResult = array_merge($lastResult, [
                    'result' => 'event',
                    'enemy' => (object) [
                        'name' => $depthGate['label'] . '入口',
                        'role' => '探索深度',
                        'type_name' => '入口',
                        'str' => 0,
                        'def' => 0,
                        'agi' => 0,
                        'mag' => 0,
                        'spr' => 0,
                    ],
                    'special_event' => 'depth_gate',
                    'depth_gate' => $depthGate,
                ]);
            }
        }

        return $lastResult;
    }

    private function firstDepthTransition(array $result): ?array
    {
        foreach ($result['exploration_progress']['depth_transitions'] ?? [] as $transition) {
            if (!is_array($transition)) {
                continue;
            }

            if (in_array((string) ($transition['key'] ?? ''), ['inner', 'deep', 'deepest', 'otherworld'], true)) {
                return $transition;
            }
        }

        return null;
    }

    private function currentDepthGateForBatch(Character $character, int $areaId, ?array $fallbackTier = null): ?array
    {
        $area = Area::find($areaId);
        if (!$area) {
            return null;
        }

        $state = app(ExplorationStateService::class)->currentFor($character);
        $depthService = app(ExplorationDepthService::class);
        $gate = null;
        if ($state && (int) $state->area_id === (int) $area->id) {
            $gate = $depthService->currentGateFor(
                $character,
                $area,
                (int) ($state->exploration_point ?? 0),
                (int) ($state->danger_rate ?? 0)
            );
        }
        $gate ??= $fallbackTier;
        $key = (string) ($gate['key'] ?? 'surface');
        if (!in_array($key, ['inner', 'deep', 'deepest', 'otherworld'], true)) {
            return null;
        }

        $label = (string) ($gate['label'] ?? '深部');
        $entranceText = match ($key) {
            'inner' => "{$area->name}の奥で、薄暗い下り道を見つけた。",
            'deep' => "{$area->name}の奥で、さらに深く続く裂け目を見つけた。",
            'deepest' => "{$area->name}の奥で、地下へ続く古い石階段を見つけた。",
            'otherworld' => "{$area->name}の奥で、空間が歪む裂け目を見つけた。",
            default => "{$area->name}の奥で、見慣れない入口を見つけた。",
        };
        $riskText = match ($key) {
            'inner' => 'この先は敵が強くなります。準備が不十分なら引き返してください。',
            'deep' => 'この先は通常よりかなり強い敵が出現します。',
            'deepest' => 'これ以上進むのは極めて危険です。',
            'otherworld' => 'この先は現実の理から外れています。生還できる保証はありません。',
            default => 'この先は危険です。',
        };
        $recommended = $depthService->recommendedLevelRangeForTier($area, $gate);
        $powerService = app(CharacterPowerService::class);
        $recommendedPower = $powerService->openingRecommendedRangeForLevels(
            (int) ($recommended['min'] ?? 1),
            (int) ($recommended['max'] ?? $recommended['min'] ?? 1)
        );
        $currentPower = $powerService->fromFinalStats(app(CharacterStatusService::class)->getFinalStats($character));

        return [
            'key' => $key,
            'label' => $label,
            'area_name' => (string) $area->name,
            'entrance_text' => $entranceText,
            'risk_text' => $riskText,
            'recommended_level_min' => (int) ($recommended['min'] ?? 0),
            'recommended_level_max' => (int) ($recommended['max'] ?? 0),
            'current_level' => (int) ($character->level ?? 1),
            'recommended_power_min' => (int) ($recommendedPower['min'] ?? 0),
            'recommended_power_max' => (int) ($recommendedPower['max'] ?? 0),
            'current_power' => $currentPower,
        ];
    }

    private function summarizeMaterialDrops(array $drops): array
    {
        $summaries = [];

        foreach ($drops as $drop) {
            if (!is_array($drop)) {
                continue;
            }

            $name = (string) ($drop['name'] ?? '素材');
            $key = (string) ($drop['material_code'] ?? $drop['material_id'] ?? $name);
            $quantity = max(1, (int) ($drop['quantity'] ?? 1));

            if (!isset($summaries[$key])) {
                $drop['name'] = $name;
                $drop['quantity'] = 0;
                $summaries[$key] = $drop;
            }

            $summaries[$key]['quantity'] = (int) ($summaries[$key]['quantity'] ?? 0) + $quantity;
        }

        return array_values($summaries);
    }

    private function rollPlayerEncounter(Character $character, Area $area): ?array
    {
        if (! $this->rollPercent(self::PLAYER_ENCOUNTER_CHANCE_PERCENT)) {
            return null;
        }

        $candidate = Character::visibleToPublic()
            ->with('currentJob')
            ->whereKeyNot($character->id)
            ->where('is_frozen', false)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subMinutes(30))
            ->whereHas('explorationState', fn ($query) => $query->where('area_id', $area->id))
            ->inRandomOrder()
            ->first();

        if (!$candidate) {
            return app(NpcFieldEncounterService::class)->roll($character, $area);
        }

        $jobName = (string) ($candidate->currentJob?->name ?? '冒険者');
        $profileService = app(CharacterProfileService::class);
        $patterns = [
            "{$area->name}の道中で、{$candidate->name}とすれ違った。軽く会釈を交わし、探索を続けた。",
            "同じく探索中の{$candidate->name}が、少し先の道を指さしてくれた。",
            "{$candidate->name}が焚き火の跡を片づけていた。{$jobName}らしい落ち着いた身のこなしだ。",
            "遠くに{$candidate->name}の姿が見えた。ヴァルゼリアを歩く冒険者は、あなた一人ではない。",
        ];
        $gift = $this->grantPlayerEncounterGift($character);

        return [
            'character_id' => (int) $candidate->id,
            'name' => (string) $candidate->name,
            'job_name' => $jobName,
            'area_name' => (string) $area->name,
            'level' => (int) ($candidate->level ?? 1),
            'icon_url' => CharacterIconCatalog::versionedAsset($candidate->icon_path),
            'avatar_frame_url' => asset($profileService->selectedAdventurerAvatarFrame($candidate, $candidate->profile_avatar_frame)),
            'message' => $patterns[array_rand($patterns)],
            'gift' => $gift,
        ];
    }

    private function grantPlayerEncounterGift(Character $character): ?array
    {
        $item = Item::where('type', 'consumable')
            ->where('name', self::PLAYER_ENCOUNTER_GIFT_ITEM_NAME)
            ->first();

        if (!$item) {
            return null;
        }

        CharacterItem::create([
            'character_id' => $character->id,
            'item_id' => $item->id,
            'is_equipped' => false,
            'is_stored' => false,
            'acquired_from' => 'player_encounter',
        ]);
        app(ExplorationItemService::class)->addBonusCarry($character, $item);

        return [
            'item_id' => (int) $item->id,
            'name' => (string) $item->name,
            'quantity' => 1,
        ];
    }

    private function batchDefeatLossSummary(array $result): array
    {
        $materialPenalty = is_array($result['material_penalty'] ?? null)
            ? $result['material_penalty']
            : [];
        $goldLoss = is_array($result['gold_loss'] ?? null)
            ? $result['gold_loss']
            : [];
        $rescueSupport = is_array($result['rescue_support'] ?? null)
            ? $result['rescue_support']
            : null;
        $valmonEggLost = is_array($result['valmon_egg_lost'] ?? null)
            ? $result['valmon_egg_lost']
            : [];

        $lostMaterials = collect($materialPenalty['materials'] ?? [])
            ->map(fn (array $material): array => [
                'name' => (string) ($material['name'] ?? '素材'),
                'quantity' => (int) ($material['quantity'] ?? 0),
            ])
            ->filter(fn (array $material): bool => $material['quantity'] > 0)
            ->values()
            ->all();
        $lostItems = collect($materialPenalty['items'] ?? [])
            ->map(fn (array $item): array => [
                'name' => (string) ($item['name'] ?? '装備'),
                'rank' => (string) ($item['rank'] ?? ''),
            ])
            ->filter(fn (array $item): bool => $item['name'] !== '')
            ->values()
            ->all();

        $supportLabel = match ($rescueSupport['type'] ?? null) {
            'emergency_rescue_request' => '緊急救助により、今回の入手品は保護されました。',
            'rescue_insurance' => '救助保険証により、入手品ロストが25%に抑えられました。',
            default => null,
        };

        return [
            'gold_amount' => (int) ($goldLoss['amount'] ?? 0),
            'gold_rate_label' => (string) ($goldLoss['rate_label'] ?? ''),
            'loss_percent' => (int) ($materialPenalty['loss_percent'] ?? 0),
            'total_lost' => (int) ($materialPenalty['total_lost'] ?? 0),
            'materials' => $lostMaterials,
            'items' => $lostItems,
            'valmon_egg_lost_count' => count($valmonEggLost),
            'support_label' => $supportLabel,
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
                $result->eventData = [
                    'sub_area_name' => $subArea?->name,
                    'sub_area_route_name' => $routeName,
                    'sub_area_discovery_id' => $discovery['discovery_id'] ?? null,
                ];
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
        $ancient = $this->treasureAncientMaterial($area);
        if ($ancient && $this->rollPercent(self::TREASURE_ANCIENT_DROP_CHANCE_PERCENT)) {
            $drop = $this->dropService->grantMaterialReward($character, $ancient, 'treasure_ancient', $baseEnemy);
            $result->drops[] = $drop;
            $drops[] = $drop['name'];
        }

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
            ->reject(fn (Material $material) => $this->isAncientMaterial($material))
            ->unique('id')
            ->values();

        return $materials->isNotEmpty() ? $materials->random() : null;
    }

    private function treasureAncientMaterial(Area $area): ?Material
    {
        if ((int) $area->id < self::FERDIA_AREA_ID_MIN || (int) $area->id > self::FERDIA_AREA_ID_MAX) {
            return null;
        }

        $materials = MaterialDrop::whereHas('enemy', fn ($query) => $query->where('area_id', $area->id))
            ->where('is_active', true)
            ->where('drop_first_clear_only', false)
            ->where('drop_rate', '>', 0)
            ->whereHas('material', fn ($query) => $query->where('name', 'like', '%古代片%'))
            ->with('material')
            ->get()
            ->pluck('material')
            ->filter()
            ->filter(fn (Material $material) => $this->isAncientMaterial($material))
            ->unique('id')
            ->values();

        return $materials->isNotEmpty() ? $materials->random() : null;
    }

    private function isAncientMaterial(Material $material): bool
    {
        return str_contains((string) $material->name, '古代片');
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

    private function mergeDropResults(array $base, array $next): array
    {
        $base['materials'] = array_merge($base['materials'] ?? [], $next['materials'] ?? []);
        $base['equipment'] = array_merge($base['equipment'] ?? [], $next['equipment'] ?? []);

        $base['monster_mark'] ??= $next['monster_mark'] ?? null;
        $base['by_slot'] ??= [];
        $nextBySlot = $next['by_slot'] ?? [];
        $base['by_slot']['material'] = array_merge($base['by_slot']['material'] ?? [], $nextBySlot['material'] ?? []);

        foreach (['weapon', 'armor', 'accessory', 'monster_mark'] as $slot) {
            $base['by_slot'][$slot] ??= $nextBySlot[$slot] ?? null;
        }

        return $base;
    }

    private function rollPercent(float $rate): bool
    {
        return $rate > 0 && random_int(1, 1_000_000) <= $rate * 10_000;
    }
}
