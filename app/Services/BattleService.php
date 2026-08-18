<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\EnemyAction;
use App\Models\PlayerValmon;
use App\Models\Skill;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use App\Services\Battle\DamageApplicationRequest;
use App\Services\Battle\DamageApplicationResult;
use App\Services\Battle\DamageApplicationService;
use App\Services\Battle\DamageSourceType;
use App\Services\Battle\DirectAttackResolution;
use App\Services\Battle\BattleResult;
use App\Services\Battle\ActionResolver;
use App\Services\Battle\HitResult;
use App\Services\Battle\JobArtHitPower;
use App\Services\Enemy\EnemyStatGenerationService;
use App\Services\Enemy\EnemyStatPreviewService;
use App\Support\JobArtEffectCatalog;

class BattleService
{
    private const PVE_ENEMY_MIN_HIT_RATE = 82;
    private const PVE_ENEMY_LATE_MIN_HIT_RATE = 88;

    protected CharacterStatusService $statusService;
    protected DamageCalculator $damageCalculator;
    protected JobArtService $jobArtService;
    protected JobArtV2FeatureGate $jobArtV2FeatureGate;
    protected JobArtV2SelectionService $jobArtV2SelectionService;
    protected JobArtV2SpCostCalculator $jobArtV2SpCostCalculator;
    protected ActionResolver $jobArtActionResolver;
    protected DamageApplicationService $damageApplicationService;
    protected JobArtV2ResourceService $jobArtV2ResourceService;
    protected JobArtV2FieldService $jobArtV2FieldService;
    protected JobArtV2PenetrationService $jobArtV2PenetrationService;
    protected JobArtV2PenetrationStanceService $jobArtV2PenetrationStanceService;
    protected JobArtV2BattleHudService $jobArtV2BattleHudService;
    protected JobArtV2PowerResolver $jobArtV2PowerResolver;
    protected JobArtV2DamageSemanticsResolver $jobArtV2DamageSemanticsResolver;
    protected JobArtV2SpPressureService $jobArtV2SpPressureService;
    protected JobArtV2BreakDebuffService $jobArtV2BreakDebuffService;
    protected JobArtV2EffectSemanticsResolver $jobArtV2EffectSemanticsResolver;
    protected JobArtV2DefenseService $jobArtV2DefenseService;
    protected JobArtV2RoleEffectService $jobArtV2RoleEffectService;
    protected JobArtV2ProgressionService $jobArtV2ProgressionService;
    protected JobArtV2CrownBalanceCatalog $jobArtV2CrownBalanceCatalog;
    protected JobArtV2UltimateCounterplayService $jobArtV2UltimateCounterplayService;
    protected JobArtFlavorTextService $jobArtFlavorTextService;

    public function __construct(
        CharacterStatusService $statusService,
        DamageCalculator $damageCalculator,
        JobArtService $jobArtService,
        ?JobArtV2FeatureGate $jobArtV2FeatureGate = null,
        ?JobArtV2SelectionService $jobArtV2SelectionService = null,
        ?JobArtV2SpCostCalculator $jobArtV2SpCostCalculator = null,
        ?ActionResolver $jobArtActionResolver = null,
        ?DamageApplicationService $damageApplicationService = null,
        ?JobArtV2ResourceService $jobArtV2ResourceService = null,
        ?JobArtV2FieldService $jobArtV2FieldService = null,
        ?JobArtV2PenetrationService $jobArtV2PenetrationService = null,
        ?JobArtV2PenetrationStanceService $jobArtV2PenetrationStanceService = null,
        ?JobArtV2BattleHudService $jobArtV2BattleHudService = null,
        ?JobArtV2PowerResolver $jobArtV2PowerResolver = null,
        ?JobArtV2DamageSemanticsResolver $jobArtV2DamageSemanticsResolver = null,
        ?JobArtV2SpPressureService $jobArtV2SpPressureService = null,
        ?JobArtV2BreakDebuffService $jobArtV2BreakDebuffService = null,
        ?JobArtV2EffectSemanticsResolver $jobArtV2EffectSemanticsResolver = null,
        ?JobArtV2DefenseService $jobArtV2DefenseService = null,
        ?JobArtV2RoleEffectService $jobArtV2RoleEffectService = null,
        ?JobArtV2ProgressionService $jobArtV2ProgressionService = null,
        ?JobArtV2CrownBalanceCatalog $jobArtV2CrownBalanceCatalog = null,
        ?JobArtV2UltimateCounterplayService $jobArtV2UltimateCounterplayService = null,
        ?JobArtFlavorTextService $jobArtFlavorTextService = null,
    ) {
        $this->statusService = $statusService;
        $this->damageCalculator = $damageCalculator;
        $this->jobArtService = $jobArtService;
        $this->jobArtV2FeatureGate = $jobArtV2FeatureGate ?? app(JobArtV2FeatureGate::class);
        $this->jobArtV2SelectionService = $jobArtV2SelectionService ?? app(JobArtV2SelectionService::class);
        $this->jobArtV2SpCostCalculator = $jobArtV2SpCostCalculator ?? app(JobArtV2SpCostCalculator::class);
        $this->jobArtActionResolver = $jobArtActionResolver ?? app(ActionResolver::class);
        $this->damageApplicationService = $damageApplicationService ?? app(DamageApplicationService::class);
        $this->jobArtV2ResourceService = $jobArtV2ResourceService ?? app(JobArtV2ResourceService::class);
        $this->jobArtV2FieldService = $jobArtV2FieldService ?? app(JobArtV2FieldService::class);
        $this->jobArtV2PenetrationService = $jobArtV2PenetrationService ?? app(JobArtV2PenetrationService::class);
        $this->jobArtV2PenetrationStanceService = $jobArtV2PenetrationStanceService ?? app(JobArtV2PenetrationStanceService::class);
        $this->jobArtV2BattleHudService = $jobArtV2BattleHudService ?? app(JobArtV2BattleHudService::class);
        $this->jobArtV2PowerResolver = $jobArtV2PowerResolver ?? app(JobArtV2PowerResolver::class);
        $this->jobArtV2DamageSemanticsResolver = $jobArtV2DamageSemanticsResolver ?? app(JobArtV2DamageSemanticsResolver::class);
        $this->jobArtV2SpPressureService = $jobArtV2SpPressureService ?? app(JobArtV2SpPressureService::class);
        $this->jobArtV2BreakDebuffService = $jobArtV2BreakDebuffService ?? app(JobArtV2BreakDebuffService::class);
        $this->jobArtV2EffectSemanticsResolver = $jobArtV2EffectSemanticsResolver ?? app(JobArtV2EffectSemanticsResolver::class);
        $this->jobArtV2DefenseService = $jobArtV2DefenseService ?? app(JobArtV2DefenseService::class);
        $this->jobArtV2RoleEffectService = $jobArtV2RoleEffectService ?? app(JobArtV2RoleEffectService::class);
        $this->jobArtV2ProgressionService = $jobArtV2ProgressionService ?? app(JobArtV2ProgressionService::class);
        $this->jobArtV2CrownBalanceCatalog = $jobArtV2CrownBalanceCatalog ?? app(JobArtV2CrownBalanceCatalog::class);
        $this->jobArtV2UltimateCounterplayService = $jobArtV2UltimateCounterplayService
            ?? app(JobArtV2UltimateCounterplayService::class);
        $this->jobArtFlavorTextService = $jobArtFlavorTextService ?? app(JobArtFlavorTextService::class);
    }

    protected function applyResolvedDamage(
        ?BattleActor $source,
        BattleActor $target,
        BattleState $state,
        int $damage,
        DamageSourceType $sourceType,
        int|string|null $sourceId = null,
        ?HitResult $hitResult = null,
        int $hitIndex = 1,
        int $hitCount = 1,
        bool $isDirect = false,
        ?string $damageCategory = null,
    ): ?DamageApplicationResult {
        if ($damage <= 0) {
            $target->takeDamage($damage);

            return null;
        }

        if (!$this->jobArtV2FeatureGate->usesDamageApplication($source, $target)) {
            $target->takeDamage($damage);

            return null;
        }

        return $this->damageApplicationService->apply(new DamageApplicationRequest(
            sourceActor: $source,
            targetActor: $target,
            resolvedDamage: $damage,
            sourceType: $sourceType,
            sourceId: $sourceId,
            battleType: $state->battleType,
            hitResult: $hitResult,
            hitIndex: $hitIndex,
            hitCount: $hitCount,
            battleState: $state,
            directAttackResolution: $isDirect
                && $source !== null
                && $state->currentSourceActionId() !== null
                ? DirectAttackResolution::fromDamageSource(
                    sourceActionId: $state->currentSourceActionId(),
                    attacker: $source,
                    target: $target,
                    hitResult: $hitResult,
                    damageCategory: (string) $damageCategory,
                    direct: true,
                    sourceType: $sourceType,
                )
                : null,
        ));
    }

    /**
     * 自動戦闘を行い、勝敗と戦闘ログを返す
     * 
     * @return BattleResult
     */
    public function executeBattle(
        Character $character,
        Enemy $enemy,
        int $goldDropRateBonusPoints = 0,
        array $options = []
    ): BattleResult
    {
        $result = new BattleResult();
        $persistCharacterState = (bool) ($options['persist_character_state'] ?? true);
        $rewardsEnabled = (bool) ($options['rewards_enabled'] ?? true);
        $explorationSupportEnabled = (bool) ($options['exploration_support_enabled'] ?? true);
        $autoUnequipInvalidItems = (bool) ($options['auto_unequip_invalid_items'] ?? true);
        $enemy->loadMissing('actions');
        if ($autoUnequipInvalidItems) {
            app(EquipmentAutoUnequipService::class)->unequipInvalidItems($character);
        }
        $character->refresh();

        // プレイヤーアクターの生成
        $stats = $this->statusService->getFinalStats($character);
        $currentJob = $character->relationLoaded('currentJob')
            ? $character->currentJob
            : $character->currentJob()->first();
        $equippedWeapon = $character->characterItems()
            ->where('is_equipped', true)
            ->whereHas('item', fn ($query) => $query->where('type', 'weapon'))
            ->with(['item', 'affixPrefix', 'affixSuffix'])
            ->first();
        $equippedArmor = $character->characterItems()
            ->where('is_equipped', true)
            ->whereHas('item', fn ($query) => $query->where('type', 'armor'))
            ->with('item')
            ->first();
        $permissionService = app(EquipmentPermissionService::class);
        $playerActor = new BattleActor($character->name, true, [
            'hp' => max(1, (int) ($options['starting_hp'] ?? $character->current_hp)),
            'max_hp' => $stats['max_hp'],
            'mp' => max(0, (int) ($options['starting_mp'] ?? ($character->current_mp ?? 0))),
            'max_mp' => $stats['max_mp'] ?? 0,
            'str' => $stats['str'],
            'def' => $stats['def'],
            'agi' => $stats['agi'],
            'mag' => $stats['mag'],
            'spr' => $stats['spr'],
            'luk' => $stats['luk'],
            'normal_attack_type' => $currentJob?->normal_attack_type,
            'current_job_id' => $character->current_job_id,
            'weapon_killer_effects' => $equippedWeapon
                ? $permissionService->effectiveKillerEffects($character, $equippedWeapon)
                : [],
            'weapon_killer_species_key' => $equippedWeapon?->killer_species_key,
            'weapon_killer_damage_rate' => $equippedWeapon ? $permissionService->effectiveKillerDamageRate($character, $equippedWeapon) : 0.0,
            'armor_resist_species_key' => $equippedArmor?->resist_species_key,
            'armor_species_damage_reduction_rate' => $equippedArmor ? $permissionService->effectiveSpeciesDamageReductionRate($character, $equippedArmor) : 0.0,
        ], clone $character);

        $playerActor->jobKey = $currentJob?->key;
        $playerActor->jobArtActivationPolicy = (string) ($character->job_art_activation_policy ?: 'normal');

        // 敵アクターの生成
        $enemyStats = $this->enemyBattleStats($character, $enemy);
        $enemySpeciesKey = trim((string) ($enemy->species_key ?: $enemy->family_key ?: ''));
        $enemySpeciesKeys = array_values(array_unique(array_filter(array_map(
            static fn ($speciesKey): string => trim((string) $speciesKey),
            (array) ($enemy->species_keys ?? [])
        ))));
        if ($enemySpeciesKey !== '' && ! in_array($enemySpeciesKey, $enemySpeciesKeys, true)) {
            array_unshift($enemySpeciesKeys, $enemySpeciesKey);
        }
        $enemyActor = new BattleActor($enemy->name, false, [
            'hp' => $enemyStats['max_hp'],
            'max_hp' => $enemyStats['max_hp'],
            'mp' => $enemy->max_mp ?? 0,
            'max_mp' => $enemy->max_mp ?? 0,
            'str' => $enemyStats['str'],
            'def' => $enemyStats['def'],
            'agi' => $enemyStats['agi'],
            'mag' => $enemyStats['mag'],
            'spr' => $enemyStats['spr'],
            'luk' => $enemyStats['luk'],
            'normal_attack_type' => $enemy->getAttribute('hero_trial_phase_key')
                ? $enemy->normal_attack_type
                : null,
            'species_key' => $enemySpeciesKey,
            'species_keys' => $enemySpeciesKeys,
        ], clone $enemy);

        $battleContext = $enemy->is_boss ? 'boss' : 'pve';
        $jobArtBattleContext = (string) ($options['job_art_context'] ?? $this->jobArtBattleContext($enemy));
        if (! in_array($jobArtBattleContext, ['pve', 'boss'], true)) {
            $jobArtBattleContext = $this->jobArtBattleContext($enemy);
        }
        $jobArts = $this->jobArtService->battleArtsFor($character, $jobArtBattleContext);
        $playerActor->jobArts = $jobArts->all();
        foreach ($jobArts as $art) {
            $playerActor->jobArtRates[(int) $art->id] = (float) $art->getAttribute('job_art_rate');
            $playerActor->jobArtOrigins[(int) $art->id] = (string) $art->getAttribute('job_art_origin');
            $playerActor->jobArtPolicies[(int) $art->id] = (string) ($art->getAttribute('job_art_activation_policy') ?: $playerActor->jobArtActivationPolicy);
            $playerActor->jobArtConditions[(int) $art->id] = (string) ($art->getAttribute('job_art_slot_condition') ?: JobArtV2SlotConditionCatalog::ALWAYS);
        }

        $result->enemyStatDisplay = [
            'danger_rate' => $enemyStats['danger_rate'],
            'danger_label' => $enemyStats['danger_label'],
            'str' => [
                'base' => $enemyStats['base_str'],
                'bonus' => $enemyStats['bonus_str'],
                'total' => $enemyStats['str'],
            ],
            'def' => [
                'base' => $enemyStats['base_def'],
                'bonus' => $enemyStats['bonus_def'],
                'total' => $enemyStats['def'],
            ],
            'hp' => [
                'base' => $enemyStats['base_hp'],
                'bonus' => $enemyStats['bonus_hp'],
                'total' => $enemyStats['max_hp'],
            ],
            'durability' => [
                'hp_multiplier' => $enemyStats['durability_hp_multiplier'],
                'def_spr_multiplier' => $enemyStats['durability_def_spr_multiplier'],
                'atk_mag_multiplier' => $enemyStats['durability_atk_mag_multiplier'],
                'tier' => $enemyStats['durability_tier'],
            ],
        ];
        $result->enemyDurability = $result->enemyStatDisplay['durability'];
        $result->playerHpBefore = $playerActor->hp;
        $result->playerMpBefore = $playerActor->mp;

        $state = new BattleState($playerActor, $enemyActor, $battleContext);
        if (isset($options['max_turns'])) {
            $state->maxTurns = max(1, (int) $options['max_turns']);
        }
        if ($explorationSupportEnabled) {
            $state->explorationSupportSnapshot = app(ExplorationSupportService::class)->beginBattle($character, $enemy);
        }
        
        $state->addLog("【戦闘開始】{$playerActor->name} は {$enemyActor->name} と遭遇した！");

        // ターンループ
        while (!$state->isBattleEnded()) {
            $state->turnCount++;
            $state->addLog("<br><br>--- ターン {$state->turnCount} ---");
            
            // 先攻後攻判定（AGI比較＋乱数）
            $playerSpeed = $playerActor->effectiveAgi() + rand(0, 5);
            $enemySpeed = $enemyActor->effectiveAgi() + rand(0, 5);
            $playerFirst = $this->jobArtV2ProgressionService->adjustInitiative(
                $playerActor,
                $enemyActor,
                $playerSpeed >= $enemySpeed,
                static fn (): bool => ($playerActor->effectiveAgi() + rand(0, 5)) >= ($enemyActor->effectiveAgi() + rand(0, 5)),
            );
            
            if ($playerFirst) {
                $this->executeAction($playerActor, $enemyActor, $state);
                if ($state->isBattleEnded()) {
                    $this->endJobArtV2Round($state);
                    break;
                }
                $this->executeAction($enemyActor, $playerActor, $state);
            } else {
                $this->executeAction($enemyActor, $playerActor, $state);
                if ($state->isBattleEnded()) {
                    $this->endJobArtV2Round($state);
                    break;
                }
                $this->executeAction($playerActor, $enemyActor, $state);
            }
            $this->endJobArtV2Round($state);
        }

        // 戦闘終了処理
        if ($playerActor->isDead()) {
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">{$playerActor->name}は、倒れてしまった……。</span>");
            $result->result = 'defeat';
            
            // 敗北時のペナルティとして、HPを最大値の30%、SPを10%にする
            $playerActor->hp = max(1, (int)($playerActor->maxHp * 0.3));
            $playerActor->mp = (int)($playerActor->maxMp * 0.1);
        } else if ($enemyActor->isDead()) {
            if ($state->pendingEnemyActionId !== null) {
                $pending = $enemy->actions->firstWhere('id', $state->pendingEnemyActionId);
                if ($pending) {
                    $state->addLog("<span class=\"text-amber-700 font-bold\">{$pending->name} は発動しなかった！</span>");
                }
            }
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">{$playerActor->name}は、{$enemyActor->name}を倒した！</span>");
            $result->result = 'victory';
            
            if ($rewardsEnabled) {
                // 報酬の付与
                $exp = $enemy->exp_reward;
                $gold = $this->rollGoldReward($enemy, $state->goldBonusPercent, $goldDropRateBonusPoints);

                $result->exp = $exp;
                $result->gold = $gold;
                if ($gold > 0) {
                    $state->addLog("<br><span class=\"text-amber-700 font-bold\">【Gold獲得】{$enemyActor->name} が持っていた <span class=\"text-amber-600 font-extrabold\">{$gold}G</span> を手に入れた！</span>");
                }

                // 職業経験値（J-EXP）の算出ロジック
                if ($enemy->job_exp_reward > 0) {
                    $result->jobExp = min(LevelService::MAX_JOB_EXP_GAIN, $enemy->job_exp_reward);
                } else {
                    $levelDiff = $enemy->level - $character->level;
                    $jobExp = 0;

                    if ($levelDiff <= -3) {
                        $jobExp = 0;
                    } elseif ($levelDiff <= -1) {
                        $jobExp = rand(0, 1);
                    } elseif ($levelDiff <= 2) {
                        $jobExp = rand(1, 2);
                    } else {
                        $jobExp = rand(1, 3);
                    }

                    if ($enemy->is_boss) {
                        $jobExp += rand(1, 2);
                    }

                    $result->jobExp = min(LevelService::MAX_JOB_EXP_GAIN, $jobExp);
                }
            }
        } else {
            $state->addLog("<br><span class=\"text-black font-extrabold text-xl\">双方が疲弊し、戦闘は終了した。</span>");
            $result->result = 'timeout';
        }

        $result->logs = $state->logs;
        $result->playerHpAfter = $playerActor->hp;
        $result->playerMpAfter = $playerActor->mp;
        $result->dropBonusPercent = $state->dropBonusPercent;
        $result->rareBonusPercent = $state->rareBonusPercent;
        $result->explorationSupportSnapshot = $state->explorationSupportSnapshot;
        $result->turnCount = $state->turnCount;
        $result->damageDealt = $enemyActor->totalDamageTaken;
        $result->damageTaken = $playerActor->totalDamageTaken;
        $result->jobArtV2Hud = $this->jobArtV2BattleHudService->present($state);

        if ($explorationSupportEnabled) {
            app(ExplorationSupportService::class)->persistBattleProcs($character, $state->explorationSupportSnapshot);
        }

        if ($persistCharacterState) {
            // キャラクターのHP/SPを更新
            $character->current_hp = $playerActor->hp;
            $character->current_mp = $playerActor->mp;
            $character->save();
        }

        return $result;
    }

    private function jobArtBattleContext(Enemy $enemy): string
    {
        if ((bool) $enemy->is_boss || str_contains((string) ($enemy->role ?? ''), 'ダンジョン主')) {
            return 'boss';
        }

        return 'pve';
    }

    private function enemyBattleStats(Character $character, Enemy $enemy): array
    {
        $str = (int) $enemy->str;
        $def = (int) $enemy->def;
        $agi = (int) $enemy->agi;
        $mag = (int) $enemy->mag;
        $spr = (int) ($enemy->spr ?? $enemy->def);
        $luk = (int) ($enemy->luk ?? 10);
        $hp = max(1, (int) $enemy->max_hp);
        $area = $enemy->relationLoaded('area') ? $enemy->area : $enemy->area()->first();
        $cityId = (int) ($area?->city_id ?? 0);

        if ($cityId >= 1 && $cityId <= 3) {
            $str = max(1, (int) floor($str * 0.92));
            $mag = max(1, (int) floor($mag * 0.92));
        }

        // 装備チェック用の耐久補正（都市帯・敵役割ごと）。危険度ボーナスより先に適用し、
        // 戦闘実行(このメソッド)と画面表示(enemyStatDisplay)が同じ値を参照するようにする。
        $durability = app(EnemyDurabilityService::class)->multiplierFor($enemy, $cityId);
        $hp = max(1, (int) round($hp * $durability['hp']));
        $def = max(1, (int) round($def * $durability['def_spr']));
        $spr = max(1, (int) round($spr * $durability['def_spr']));
        $str = max(1, (int) round($str * $durability['atk_mag']));
        $mag = max(1, (int) round($mag * $durability['atk_mag']));

        $regionDepth = app(RegionDepthDungeonService::class);
        if ($enemy->getAttribute('region_depth_dungeon_key')) {
            $dungeonKey = (string) $enemy->getAttribute('region_depth_dungeon_key');
            $baseMultipliers = $regionDepth->baseEnemyStatMultipliers($dungeonKey);
            $hp = max(1, (int) floor($hp * $baseMultipliers['hp']));
            $str = max(1, (int) floor($str * $baseMultipliers['str']));
            $def = max(1, (int) floor($def * $baseMultipliers['def']));
            $agi = max(1, (int) floor($agi * $baseMultipliers['agi']));
            $mag = max(1, (int) floor($mag * $baseMultipliers['mag']));
            $spr = max(1, (int) floor($spr * $baseMultipliers['spr']));
            $luk = max(1, (int) floor($luk * $baseMultipliers['luk']));
            $multipliers = $regionDepth->enemyMultipliers((int) $enemy->getAttribute('region_depth_danger_rate'), $dungeonKey);
            $totals = [
                'hp' => max(1, (int) floor($hp * $multipliers['hp'])),
                'str' => max(1, (int) floor($str * $multipliers['main'])),
                'def' => max(1, (int) floor($def * $multipliers['main'])),
                'agi' => max(1, (int) floor($agi * $multipliers['agi_luk'])),
                'mag' => max(1, (int) floor($mag * $multipliers['main'])),
                'spr' => max(1, (int) floor($spr * $multipliers['main'])),
                'luk' => max(1, (int) floor($luk * $multipliers['agi_luk'])),
            ];
            $danger = [
                'rate' => (int) $enemy->getAttribute('region_depth_danger_rate'),
                'label' => $regionDepth->dangerLabel((int) $enemy->getAttribute('region_depth_danger_rate')),
                'hp' => $totals['hp'] - $hp, 'str' => $totals['str'] - $str, 'def' => $totals['def'] - $def,
                'agi' => $totals['agi'] - $agi, 'mag' => $totals['mag'] - $mag, 'spr' => $totals['spr'] - $spr, 'luk' => $totals['luk'] - $luk,
            ];
        } else {
            $danger = $this->enemyDangerBonus($character, $enemy, [
                'hp' => $hp,
                'str' => $str,
                'def' => $def,
                'agi' => $agi,
                'mag' => $mag,
                'spr' => $spr,
                'luk' => $luk,
            ]);
        }

        return [
            'base_hp' => $hp,
            'base_str' => $str,
            'base_def' => $def,
            'max_hp' => $hp + $danger['hp'],
            'str' => $str + $danger['str'],
            'def' => $def + $danger['def'],
            'agi' => $agi + $danger['agi'],
            'mag' => $mag + $danger['mag'],
            'spr' => $spr + $danger['spr'],
            'luk' => $luk + $danger['luk'],
            'bonus_hp' => $danger['hp'],
            'bonus_str' => $danger['str'],
            'bonus_def' => $danger['def'],
            'danger_rate' => $danger['rate'],
            'danger_label' => $danger['label'],
            'durability_hp_multiplier' => $durability['hp'],
            'durability_def_spr_multiplier' => $durability['def_spr'],
            'durability_atk_mag_multiplier' => $durability['atk_mag'],
            'durability_tier' => $durability['tier'],
        ];
    }

    private function rollGoldReward(Enemy $enemy, int $goldBonusPercent = 0, int $goldDropRateBonusPoints = 0): int
    {
        $rate = min(100, max(0, (float) config($enemy->is_boss ? 'gold.battle.boss_drop_rate' : 'gold.battle.normal_drop_rate', $enemy->is_boss ? 12 : 5) + $goldDropRateBonusPoints));
        if (random_int(1, 10000) > (int) round($rate * 100)) {
            return 0;
        }

        $base = max(5, (int) ($enemy->gold_reward ?? 0));
        $variance = random_int(80, 120) / 100;
        $amount = (int) floor($base * $variance);

        if ($goldBonusPercent > 0) {
            $amount = (int) floor($amount * (100 + $goldBonusPercent) / 100);
        }

        return max(1, $amount);
    }

    private function enemyDangerBonus(Character $character, Enemy $enemy, array $baseStats = []): array
    {
        if ($enemy->getAttribute('skip_danger_bonus')) {
            return $this->emptyDangerBonus();
        }

        if ($enemy->is_boss) {
            return $this->emptyDangerBonus();
        }

        $stateService = app(ExplorationStateService::class);
        $state = $stateService->currentFor($character);
        if (!$state || (int) $state->area_id !== (int) $enemy->area_id) {
            return $this->emptyDangerBonus();
        }

        $dangerRate = max(0, (int) ($state->danger_rate ?? 0));

        $depthService = app(ExplorationDepthService::class);
        $area = $enemy->relationLoaded('area') ? $enemy->area : $enemy->area()->first();
        $depthTier = $area
            ? $depthService->activeTierFor($character, $area, (int) ($state->exploration_point ?? 0), $dangerRate)
            : $depthService->tierFor((int) ($state->exploration_point ?? 0), $dangerRate);
        if ($dangerRate <= 0 && ($depthTier['key'] ?? 'surface') === 'surface') {
            return $this->emptyDangerBonus($stateService->dangerLabel($dangerRate));
        }

        $enemyLevel = max(1, (int) ($enemy->level ?? 1));
        $targetLevel = $area
            ? max($enemyLevel, $depthService->targetLevelForTier($area, $depthTier))
            : $enemyLevel;
        $levelScale = max(1.0, $targetLevel / $enemyLevel);

        // 危険度と探索深度で段階的に強化する。
        // 低Lv敵を深度帯へ引き上げる際、レベル比をそのまま累乗するとスライム等が極端に跳ねるため、
        // 平方根ベースで「少し強い同系統の敵」に留める。
        $rate = min(8.0, ($dangerRate * 0.0025) + $depthService->enemyPowerBonusForTier($depthTier));
        $levelStatMultiplier = 1.0 + ((sqrt($levelScale) - 1.0) * 0.75);
        $statMultiplier = max(1.0 + $rate, $levelStatMultiplier);
        $hpMultiplier = max(
            $depthService->enemyHpMultiplierForTier($depthTier),
            sqrt($levelScale)
        );

        $base = [
            'hp' => max(1, (int) ($baseStats['hp'] ?? $enemy->max_hp)),
            'str' => max(1, (int) ($baseStats['str'] ?? $enemy->str)),
            'def' => max(1, (int) ($baseStats['def'] ?? $enemy->def)),
            'agi' => max(1, (int) ($baseStats['agi'] ?? $enemy->agi)),
            'mag' => max(1, (int) ($baseStats['mag'] ?? $enemy->mag)),
            'spr' => max(1, (int) ($baseStats['spr'] ?? ($enemy->spr ?? $enemy->def))),
            'luk' => max(1, (int) ($baseStats['luk'] ?? ($enemy->luk ?? 10))),
        ];

        $totals = [
            'hp' => max(1, (int) floor($base['hp'] * $hpMultiplier)),
            'str' => max(1, (int) floor($base['str'] * $statMultiplier)),
            'def' => max(1, (int) floor($base['def'] * $statMultiplier)),
            'agi' => max(1, (int) floor($base['agi'] * $statMultiplier)),
            'mag' => max(1, (int) floor($base['mag'] * $statMultiplier)),
            'spr' => max(1, (int) floor($base['spr'] * $statMultiplier)),
            'luk' => max(1, (int) floor($base['luk'] * $statMultiplier)),
        ];

        if (($depthTier['key'] ?? 'surface') !== 'surface' && $area) {
            $generated = $this->generatedEnemyStatsForDepth($enemy, $targetLevel);
            foreach ($generated as $key => $value) {
                $totals[$key] = max($totals[$key], $value);
            }
        }

        return [
            'rate' => $dangerRate,
            'label' => $stateService->dangerLabel($dangerRate),
            'hp' => max(0, $totals['hp'] - $base['hp']),
            'str' => max(0, $totals['str'] - $base['str']),
            'def' => max(0, $totals['def'] - $base['def']),
            'agi' => max(0, $totals['agi'] - $base['agi']),
            'mag' => max(0, $totals['mag'] - $base['mag']),
            'spr' => max(0, $totals['spr'] - $base['spr']),
            'luk' => max(0, $totals['luk'] - $base['luk']),
        ];
    }

    private function generatedEnemyStatsForDepth(Enemy $enemy, int $targetLevel): array
    {
        $metadata = app(EnemyStatPreviewService::class)->metadataFor($enemy);
        $generated = app(EnemyStatGenerationService::class)->generate(
            $targetLevel,
            $metadata['family_key'] ?? null,
            $metadata['variant_key'] ?? null,
            $metadata['role_key'] ?? null,
        );
        $stats = $generated['stats'];

        return [
            'hp' => max(1, (int) $stats['hp']),
            'str' => max(1, (int) $stats['attack']),
            'def' => max(1, (int) $stats['defense']),
            'agi' => max(1, (int) $stats['speed']),
            'mag' => max(1, (int) $stats['magic']),
            'spr' => max(1, (int) $stats['spirit']),
            'luk' => max(1, (int) $stats['luck']),
        ];
    }

    private function emptyDangerBonus(string $label = '安定'): array
    {
        return [
            'rate' => 0,
            'label' => $label,
            'hp' => 0,
            'str' => 0,
            'def' => 0,
            'agi' => 0,
            'mag' => 0,
            'spr' => 0,
            'luk' => 0,
        ];
    }

    /**
     * 行動（通常攻撃またはスキル攻撃）
     */
    protected function executeAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $sourceActionId = $this->jobArtV2ResourceService->beginAction($attacker, $state);
        if ($sourceActionId !== null) {
            $this->jobArtV2RoleEffectService->beginAction($attacker, $state, $sourceActionId);
        }

        try {
        // 自分の手番が来たので、前ターンの防御状態・軽減状態を解除する
        $attacker->isDefending = false;
        $attacker->damageReductionRate = 0;

        // プレイヤーの行動（既存ロジック）
        if ($attacker->isPlayer) {
            $defenderHpBeforeAction = $defender->hp;
            // スキル発動判定 (通常攻撃前)
            $usedSkill = false;
            $this->tickJobArtCooldowns($state);
            $jobArt = $this->selectJobArtForAction($attacker, $state);
            if ($jobArt && (!$this->jobArtV2ResourceService->enabledFor($attacker)
                || $this->jobArtV2SelectionService->isEligible($attacker, $state, $jobArt, (int) $jobArt->id)
            )) {
                $spCost = $this->jobArtSpCost($attacker, $jobArt);
                $attacker->mp -= $spCost;
                $this->executeJobArtAction($attacker, $defender, $state, $jobArt);
                $usedSkill = true;
            }

            if (!$usedSkill) {
                // 通常攻撃
                $this->executeNormalAttack($attacker, $defender, $state);
            }

            $this->tryValmonAssistAttack($attacker, $defender, $state);

            if (!$state->isBattleEnded()) {
                $this->tickPlayerConditionsAfterAction($attacker, $state, $defender->hp < $defenderHpBeforeAction);
            }
        } 
        // 敵の行動（AIロジック）
        else {
            $this->executeEnemyAction($attacker, $defender, $state);
        }
        } finally {
            $this->jobArtV2ResourceService->finishAction($attacker, $state);
            $this->jobArtV2UltimateCounterplayService->finishAction($attacker, $state);
        }
    }

    private function tryValmonAssistAttack(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        if (! in_array($state->battleType, ['pve', 'boss'], true)
            || $state->valmonAssistRolled
            || $state->valmonAssistUsed
            || ! $attacker->isPlayer) {
            return;
        }

        if (! $attacker->originalModel instanceof Character) {
            return;
        }

        $valmonService = app(ValmonService::class);
        $partner = $valmonService->partnerFor($attacker->originalModel);
        $spec = $partner ? $valmonService->assistAttackSpec($partner) : null;
        if (! $partner || ! $spec) {
            return;
        }

        $rate = max(0, (float) ($spec['rate'] ?? 0));
        $state->valmonAssistRolled = true;
        if ($rate <= 0 || random_int(1, 10000) > (int) round($rate * 100)) {
            return;
        }

        $normalDamage = $attacker->usesMagForNormalAttack()
            ? $this->damageCalculator->calculateMagicalDamage($attacker, $defender, 100, false)
            : $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, 100, false);
        $baseDamage = max(1, (int) floor($normalDamage * (float) ($spec['power_rate'] ?? 0.1)));
        $damage = $this->applyValmonAssistDamageVariance($baseDamage, $spec);

        $this->applyResolvedDamage(
            $attacker,
            $defender,
            $state,
            $damage,
            DamageSourceType::OTHER,
            $partner->id,
        );
        $state->valmonAssistUsed = true;
        $state->addDamageLog($this->valmonBondArtActivationLog($attacker, $defender, $partner, $spec, $damage));
        $this->logGutsIfTriggered($defender, $state);
    }

    private function valmonBondArtActivationLog(
        BattleActor $attacker,
        BattleActor $defender,
        PlayerValmon $partner,
        array $spec,
        int $damage
    ): string {
        $styleLabel = (string) ($spec['style_label'] ?? '均衡');
        $techniqueName = (string) ($spec['technique_name'] ?? '響き合う絆の一撃');
        $lines = [
            '<span class="battle-log-special-title">【絆技・' . e($styleLabel) . '】'
                . e($techniqueName) . '――' . e($partner->displayName()) . 'による追撃！</span>',
        ];

        $phrase = trim((string) ($spec['activation_phrase'] ?? ''));
        if ($phrase !== '') {
            $lines[] = '<span class="battle-log-special-phrase">'
                . e($this->formatValmonBondText($phrase, $attacker, $defender, $partner, $techniqueName))
                . '</span>';
        }

        $description = trim((string) ($spec['activation_description'] ?? ''));
        if ($description !== '') {
            $lines[] = '<span class="battle-log-special-description">'
                . e($this->formatValmonBondText($description, $attacker, $defender, $partner, $techniqueName))
                . '</span>';
        }

        $lines[] = '<span class="text-teal-700 font-bold">' . e($defender->name)
            . 'に <span class="text-red-600 font-extrabold">' . number_format($damage)
            . '</span> ダメージ！</span>';

        return implode('<br>', $lines);
    }

    protected function applyValmonAssistDamageVariance(int $baseDamage, array $spec): int
    {
        $min = max(0, (int) ($spec['damage_variance_min_percent'] ?? 70));
        $max = max($min, (int) ($spec['damage_variance_max_percent'] ?? 130));

        return max(1, (int) floor($baseDamage * random_int($min, $max) / 100));
    }

    private function formatValmonBondText(
        string $text,
        BattleActor $attacker,
        BattleActor $defender,
        PlayerValmon $partner,
        string $techniqueName
    ): string {
        return strtr($text, [
            '{user}' => $attacker->name,
            '{valmon}' => $partner->displayName(),
            '{target}' => $defender->name,
            '{skill}' => $techniqueName,
        ]);
    }

    private function tickJobArtCooldowns(BattleState $state): void
    {
        foreach ($state->jobArtCooldowns as $skillId => $remaining) {
            $remaining = max(0, (int) $remaining - 1);
            if ($remaining <= 0) {
                unset($state->jobArtCooldowns[$skillId]);
            } else {
                $state->jobArtCooldowns[$skillId] = $remaining;
            }
        }
    }

    private function selectJobArtForTurn(BattleActor $attacker, BattleState $state): ?Skill
    {
        foreach ($attacker->jobArts as $art) {
            if (!$art instanceof Skill) {
                continue;
            }
            $skillId = (int) $art->id;
            if (($state->jobArtCooldowns[$skillId] ?? 0) > 0) {
                continue;
            }
            if ($art->max_uses_per_battle !== null && ($state->jobArtUseCounts[$skillId] ?? 0) >= (int) $art->max_uses_per_battle) {
                continue;
            }
            $spCost = $this->jobArtSpCost($attacker, $art);
            $policy = (string) ($attacker->jobArtPolicies[$skillId] ?? $attacker->jobArtActivationPolicy);
            if (!$this->canActivateByPolicy($attacker, $spCost, $policy)) {
                continue;
            }
            if (!$this->canActivateRecoveryArt($attacker, $art)) {
                continue;
            }
            if (rand(1, 100) <= $art->effectiveActivationRate()) {
                return $art;
            }
        }

        return null;
    }

    protected function selectJobArtForAction(BattleActor $attacker, BattleState $state): ?Skill
    {
        if ($this->jobArtV2FeatureGate->usesDynamicSingle($attacker)) {
            return $this->jobArtV2SelectionService->selectForTurn($attacker, $state)->skill;
        }

        return $this->selectJobArtForTurn($attacker, $state);
    }

    private function jobArtSpCost(BattleActor $attacker, Skill $skill): int
    {
        return $this->jobArtV2SpCostCalculator->forActor($attacker, $skill);
    }

    private function canActivateByPolicy(BattleActor $actor, int $spCost, string $policy): bool
    {
        if ($actor->mp < $spCost) {
            return false;
        }

        $spRate = $actor->maxMp > 0
            ? $actor->mp / $actor->maxMp
            : 0.0;

        return match ($policy) {
            'aggressive' => true,
            'normal' => $spRate >= 0.30,
            'conserve' => $spRate >= 0.60,
            default => $spRate >= 0.30,
        };
    }

    private function canActivateRecoveryArt(BattleActor $actor, Skill $skill): bool
    {
        $needsHp = $skill->isHealArt()
            || in_array((string) $skill->effect_template, ['HEAL', 'HEAL_CLEANSE'], true)
            || ((string) $skill->effect_template === 'DRAIN' && (float) $skill->drain_hp_rate > 0)
            || (int) $skill->heal_percent > 0;
        $needsSp = (int) $skill->mp_recover_percent > 0;
        if ($needsHp || $needsSp) {
            return ($needsHp && $this->hasMissingHp($actor))
                || ($needsSp && $this->hasMissingSp($actor));
        }

        return true;
    }

    private function hasMissingHp(BattleActor $actor): bool
    {
        return $actor->maxHp > 0 && $actor->hp < $actor->maxHp;
    }

    private function hasMissingSp(BattleActor $actor): bool
    {
        return $actor->maxMp > 0 && $actor->mp < $actor->maxMp;
    }

    /**
     * 通常の物理攻撃処理（共通化）
     */
    protected function executeNormalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerMultiplier = 100): void
    {
        $this->jobArtV2RoleEffectService->markNonJobArtAction($attacker, $state);

        if (!$this->isPveAttackHit($attacker, $defender, $state)) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            $this->jobArtV2ResourceService->recordNormalAttackResolution($attacker, $defender, $state, HitResult::MISS);
            return;
        }

        $isCrit = $this->damageCalculator->isCritical($attacker, $defender);
        $damage = $attacker->usesMagForNormalAttack()
            ? $this->damageCalculator->calculateMagicalDamage($attacker, $defender, $powerMultiplier, $isCrit)
            : $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $powerMultiplier, $isCrit);
        $damage = $this->applyPveKillerDamage($damage, $attacker, $defender, $state);
        $damage = $this->jobArtV2FieldService->modifyDamage($attacker, $state, $damage, DamageSourceType::NORMAL_ATTACK);
        $damageResult = $this->applyResolvedDamage(
            $attacker,
            $defender,
            $state,
            $damage,
            DamageSourceType::NORMAL_ATTACK,
            null,
            HitResult::HIT,
            1,
            1,
            true,
            $attacker->usesMagForNormalAttack() ? 'magical' : 'physical',
        );
        $damage = $damageResult?->requestedDamage ?? $damage;
        $this->tryExplorationSupportHerbal($defender, $state);

        $critText = $isCrit ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : "";
        $damageClass = $attacker->usesMagForNormalAttack() ? 'text-purple-600' : 'text-red-600';
        $state->addDamageLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"{$damageClass} font-extrabold text-lg\">{$damage}</span> のダメージ！");
        $this->jobArtV2ResourceService->recordNormalAttackResolution($attacker, $defender, $state, HitResult::HIT);
        $this->logGutsIfTriggered($defender, $state);
    }

    protected function executePhysicalAttack(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        int $powerMultiplier = 100,
        ?int $overrideDef = null,
        ?bool $forceCrit = null,
        bool $skipHitCheck = false,
        DamageSourceType $sourceType = DamageSourceType::OTHER,
        int|string|null $sourceId = null,
        ?HitResult $hitResult = null,
        int $hitIndex = 1,
        int $hitCount = 1,
        ?Skill $jobArtSkill = null,
    ): void
    {
        if (!$skipHitCheck && !$this->isPveAttackHit($attacker, $defender, $state)) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            return;
        }

        $criticalBonus = $jobArtSkill !== null
            ? $this->jobArtV2RoleEffectService->criticalBonusPoints($attacker, $jobArtSkill)
            : 0.0;
        $isCrit = $forceCrit ?? $this->damageCalculator->isCritical($attacker, $defender, $criticalBonus);
        $statOverrides = $jobArtSkill !== null
            ? $this->jobArtV2RoleEffectService->damageStatOverrides($attacker, $defender, $jobArtSkill)
            : ['attack' => null, 'def' => null, 'spr' => null];
        $damage = $this->damageCalculator->calculatePhysicalDamage(
            $attacker,
            $defender,
            $powerMultiplier,
            $isCrit,
            $statOverrides['attack'],
            $statOverrides['def'] ?? $overrideDef,
        );
        $damage = $this->applyPveKillerDamage($damage, $attacker, $defender, $state);
        $damage = $this->jobArtV2FieldService->modifyDamage($attacker, $state, $damage, $sourceType);
        if ($sourceType === DamageSourceType::JOB_ART && $jobArtSkill !== null) {
            $damage = $this->jobArtV2RoleEffectService->modifyJobArtDamage($attacker, $state, $jobArtSkill, $damage);
        }
        $damageResult = $this->applyResolvedDamage(
            $attacker,
            $defender,
            $state,
            $damage,
            $sourceType,
            $sourceId,
            $hitResult,
            $hitIndex,
            $hitCount,
            true,
            'physical',
        );
        $damage = $damageResult?->requestedDamage ?? $damage;
        $this->tryExplorationSupportHerbal($defender, $state);

        $critText = $isCrit ? "<span class=\"text-orange-500 font-bold\">【痛恨の一撃！】</span>" : "";
        $state->addDamageLog("{$attacker->name} の攻撃！ {$critText} {$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
        $this->logGutsIfTriggered($defender, $state);
    }

    /**
     * 敵キャラクター専用の行動ロジック（型に基づくAI）
     */
    protected function executeEnemyAction(BattleActor $attacker, BattleActor $defender, BattleState $state): void
    {
        $enemyModel = $attacker->originalModel;
        if ($enemyModel instanceof Enemy && $enemyModel->actions->isNotEmpty()) {
            $this->executeConfiguredEnemyAction($attacker, $defender, $state, $enemyModel);
            return;
        }

        $typeName = $enemyModel->type_name ?? '標準型';
        $isBoss = $enemyModel->is_boss ?? false;

        $rand = rand(1, 100);

        // 1. ボスの特別行動 (15%で大技)
        if ($isBoss && $rand <= 15) {
            if ($typeName === '魔法型') {
                $state->addLog("<span class=\"text-purple-600 font-extrabold\">【大技】{$attacker->name} が魔力を解き放つ！！</span>");
                $this->executeMagicalAttack($attacker, $defender, $state, 180);
            } else {
                $state->addLog("<span class=\"text-purple-600 font-extrabold\">【大技】{$attacker->name} の強烈な一撃が炸裂する！！</span>");
                $this->executePhysicalAttack($attacker, $defender, $state, 180);
            }
            return;
        }

        // 2. 型に応じた行動分岐
        // 乱数の再取得（ボス行動判定とは別枠で計算）
        $rand = rand(1, 100);

        switch ($typeName) {
            case '魔法型':
                if ($attacker->usesMagForNormalAttack()) {
                    $this->executeMagicalAttack($attacker, $defender, $state);
                } elseif ($rand <= 70) {
                    $this->executeMagicalAttack($attacker, $defender, $state);
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;

            case '耐久型':
            case '重装型':
                if ($rand <= 25) {
                    $state->addLog("<span class=\"text-blue-600 font-bold\">{$attacker->name} は防御の構えをとった！</span>");
                    $attacker->isDefending = true;
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;

            case '高速型':
                if ($rand <= 20) {
                    $state->addLog("<span class=\"text-blue-600 font-bold\">{$attacker->name} の連続攻撃！</span>");
                    $this->executePhysicalAttack($attacker, $defender, $state, 80);
                    if (!$defender->isDead()) {
                        $this->executePhysicalAttack($attacker, $defender, $state, 80);
                    }
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;

            case 'アンデッド型':
                if ($rand <= 30) {
                    $state->addLog("<span class=\"text-purple-600 font-bold\">{$attacker->name} は生命力を吸収しようと襲いかかった！</span>");
                    $beforeHp = $defender->hp;
                    $this->executePhysicalAttack($attacker, $defender, $state, 100);
                    $damageDealt = $beforeHp - $defender->hp;
                    if ($damageDealt > 0) {
                        $healAmount = (int)($damageDealt * 0.5);
                        $actualHeal = $this->jobArtV2FieldService->applyHpHeal($attacker, $state, $healAmount);
                        $state->addLog("<span class=\"text-green-600 font-bold\">{$attacker->name} はHPを {$actualHeal} 回復した！</span>");
                    }
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;

            case '竜型':
                if ($rand <= 30) {
                    $state->addLog("<span class=\"text-red-600 font-extrabold\">{$attacker->name} のドラゴンブレス！！</span>");
                    // 魔法と物理の複合ダメージのような扱い(高倍率魔法)
                    $this->executeMagicalAttack($attacker, $defender, $state, 150);
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state, 120); // 竜は通常攻撃も少し重い
                }
                break;

            case '物理型':
            case '標準型':
            default:
                if ($rand <= 20) {
                    $state->addLog("<span class=\"text-orange-600 font-bold\">{$attacker->name} は渾身の力を込めて殴りかかった！</span>");
                    $this->executePhysicalAttack($attacker, $defender, $state, 150);
                } else {
                    $this->executePhysicalAttack($attacker, $defender, $state);
                }
                break;
        }
    }

    private function executeConfiguredEnemyAction(BattleActor $attacker, BattleActor $defender, BattleState $state, Enemy $enemy): void
    {
        if ($state->pendingEnemyActionId !== null) {
            $pending = $enemy->actions->firstWhere('id', $state->pendingEnemyActionId);
            if (!$pending) {
                $state->pendingEnemyActionId = null;
            } elseif ($state->pendingEnemyActionTurns > 1) {
                $state->pendingEnemyActionTurns--;
                $state->addLog("<span class=\"battle-log-telegraph\">{$attacker->name} はまだ力を溜めている……。</span>");
                return;
            } else {
                $state->pendingEnemyActionId = null;
                $state->pendingEnemyActionTurns = 0;
                $this->jobArtV2UltimateCounterplayService->markPveTelegraphExecuting($state, true);
                try {
                    $this->executeEnemyActionEffect($attacker, $defender, $state, $pending, true);
                } finally {
                    $this->jobArtV2UltimateCounterplayService->markPveTelegraphExecuting($state, false);
                    $this->jobArtV2UltimateCounterplayService->completePveTelegraphedEnemyAction($state);
                }
                return;
            }
        }

        $action = $this->selectEnemyAction($enemy, $state, $attacker);
        if (!$action) {
            $this->executePhysicalAttack($attacker, $defender, $state);
            return;
        }

        if ($action->is_telegraphed) {
            $state->pendingEnemyActionId = (int) $action->id;
            $state->pendingEnemyActionTurns = max(1, (int) $action->telegraph_turns);
            $state->enemyTelegraphContext = [
                'cycle_id' => $state->enemyTelegraphSequence++,
                'action_id' => (int) $action->id,
                'action_name' => (string) $action->name,
                'can_be_guarded' => (bool) $action->can_be_guarded,
                'executing' => false,
                'delayed' => false,
                'lineage_suppressed' => false,
                'eclipse_backlash' => false,
            ];
            $this->markEnemyActionUsed($action, $state);
            $state->addLog("<span class=\"battle-log-telegraph\">⚠ {$attacker->name} は {$action->name} の気配を見せた！</span>");
            return;
        }

        $this->executeEnemyActionEffect($attacker, $defender, $state, $action);
    }

    private function selectEnemyAction(Enemy $enemy, BattleState $state, BattleActor $attacker): ?EnemyAction
    {
        $available = $enemy->actions->filter(fn (EnemyAction $action): bool => $this->canUseEnemyAction($action, $state, $attacker));
        if ($available->isEmpty()) {
            return null;
        }

        $guaranteed = $available->filter(fn (EnemyAction $action): bool => $action->guarantee_first_use
            && (int) ($action->trigger_turn ?? 0) === $state->turnCount
            && (int) ($state->enemyActionUseCounts[$action->id] ?? 0) === 0);
        if ($guaranteed->isNotEmpty()) {
            return $this->weightedEnemyAction($guaranteed);
        }

        $rate = $enemy->is_boss ? 30 : (in_array((string) $enemy->role_key, ['strong', 'rare'], true) ? 25 : 20);
        if (random_int(1, 100) > $rate) {
            return null;
        }

        return $this->weightedEnemyAction($available);
    }

    private function canUseEnemyAction(EnemyAction $action, BattleState $state, BattleActor $attacker): bool
    {
        if (!$action->can_use_on_first_turn && $state->turnCount <= 1) {
            return false;
        }
        if ($action->max_uses_per_battle !== null && (int) ($state->enemyActionUseCounts[$action->id] ?? 0) >= (int) $action->max_uses_per_battle) {
            return false;
        }

        $lastTurn = $state->enemyActionUseTurns[$action->id] ?? null;
        if ($lastTurn !== null && $state->turnCount - (int) $lastTurn <= max(1, (int) $action->cooldown_turns)) {
            return false;
        }

        return match ((string) $action->trigger_key) {
            'enemy_hp_below' => $attacker->hp * 100 <= $attacker->maxHp * (int) $action->trigger_value,
            'turn_at_least' => $state->turnCount >= (int) $action->trigger_value,
            default => true,
        };
    }

    private function weightedEnemyAction($actions): EnemyAction
    {
        $total = max(1, (int) $actions->sum(fn (EnemyAction $action): int => max(1, (int) $action->selection_weight)));
        $roll = random_int(1, $total);
        foreach ($actions as $action) {
            $roll -= max(1, (int) $action->selection_weight);
            if ($roll <= 0) {
                return $action;
            }
        }

        return $actions->first();
    }

    private function markEnemyActionUsed(EnemyAction $action, BattleState $state): void
    {
        $state->enemyActionUseTurns[$action->id] = $state->turnCount;
        $state->enemyActionUseCounts[$action->id] = (int) ($state->enemyActionUseCounts[$action->id] ?? 0) + 1;
    }

    private function executeEnemyActionEffect(BattleActor $attacker, BattleActor $defender, BattleState $state, EnemyAction $action, bool $alreadyMarked = false): void
    {
        if (!$alreadyMarked) {
            $this->markEnemyActionUsed($action, $state);
        }
        $state->addLog("<span class=\"battle-log-enemy-action\">【敵技】{$attacker->name} の {$action->name}！</span>");
        $beforeHp = $defender->hp;
        $suppressSecondary = (bool) ($state->enemyTelegraphContext['lineage_suppressed'] ?? false);

        switch ((string) $action->action_type) {
            case 'current_hp_percent':
                $this->executeCurrentHpPercentAttack($attacker, $defender, $state, (int) $action->effect_percent);
                return;
            case 'critical_strike':
                $this->executePhysicalAttack($attacker, $defender, $state, (int) $action->power_percent, null, true);
                return;
            case 'multi_hit':
                for ($hit = 0; $hit < max(1, (int) $action->hit_count); $hit++) {
                    $this->executePhysicalAttack($attacker, $defender, $state, (int) $action->power_percent);
                    if ($defender->isDead()) {
                        break;
                    }
                }
                return;
            case 'def_pierce':
                $ignoreRate = max(0, min(100, (int) $action->effect_percent));
                $this->executePhysicalAttack($attacker, $defender, $state, (int) $action->power_percent, (int) floor($defender->effectiveDef() * (1 - ($ignoreRate / 100))));
                return;
            case 'charge':
                $this->executeCappedPhysicalAttack($attacker, $defender, $state, (int) $action->power_percent, 60);
                return;
            case 'self_buff':
                if ($suppressSecondary) {
                    $state->addLog('<span class="text-sky-700 font-bold">封式の場が敵の大技の追加強化を抑えた！</span>');
                    return;
                }
                $rate = max(0, (int) $action->effect_percent) / 100;
                $attacker->str += (int) floor($attacker->baseStr * $rate);
                $attacker->mag += (int) floor($attacker->baseMag * $rate);
                $state->addLog("<span class=\"text-indigo-700 font-bold\">{$attacker->name} のATKとMAGが高まった！</span>");
                return;
            default:
                $this->executePhysicalAttack($attacker, $defender, $state, (int) $action->power_percent);
        }

        if ($suppressSecondary || $defender->hp >= $beforeHp || $defender->isDead()) {
            return;
        }

        match ((string) $action->action_type) {
            'burn' => $this->applyEnemyCondition($defender, $state, 'burn', (int) $action->duration_turns, 0.04),
            'poison' => $this->applyPoisonCondition($defender, $state, (int) $action->duration_turns),
            'bleed' => $this->applyEnemyCondition($defender, $state, 'bleed', (int) $action->duration_turns, 0.03),
            'def_down' => $this->applyEnemyCondition($defender, $state, 'def_down', (int) $action->duration_turns, (int) $action->effect_percent / 100),
            'slow' => $this->applyEnemyCondition($defender, $state, 'slow', (int) $action->duration_turns, (int) $action->effect_percent / 100),
            'recovery_block' => $this->applyEnemyCondition($defender, $state, 'recovery_block', (int) $action->duration_turns, (int) $action->effect_percent / 100),
            default => null,
        };
    }

    private function executeCurrentHpPercentAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $percent): void
    {
        $damage = (int) floor($defender->hp * max(0, $percent) / 100);
        $damage = min($damage, max(0, $defender->hp - 1));
        if ($defender->isPlayer) {
            $damage = app(ExplorationSupportService::class)->reduceDirectDamage($damage, $state->explorationSupportSnapshot);
        }
        $this->applyResolvedDamage($attacker, $defender, $state, $damage, DamageSourceType::PURE);
        $this->tryExplorationSupportHerbal($defender, $state);
        $state->addLog("<span class=\"battle-log-percent\">{$defender->name} の現在HPを削り、{$damage} ダメージ！</span>");
    }

    private function executeCappedPhysicalAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, int $powerPercent, int $capPercent): void
    {
        if (!$this->isPveAttackHit($attacker, $defender, $state)) {
            $state->addLog("{$attacker->name} の攻撃！……しかし、{$defender->name} はかわした！");
            return;
        }

        $damage = $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $powerPercent, false);
        $damage = min($damage, max(1, (int) floor($defender->maxHp * $capPercent / 100)));
        $damage = $this->applyPveKillerDamage($damage, $attacker, $defender, $state);
        $damageResult = $this->applyResolvedDamage(
            $attacker,
            $defender,
            $state,
            $damage,
            DamageSourceType::OTHER,
            null,
            HitResult::HIT,
            1,
            1,
            true,
            'physical',
        );
        $damage = $damageResult?->requestedDamage ?? $damage;
        $this->tryExplorationSupportHerbal($defender, $state);
        $state->addDamageLog("{$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
        $this->logGutsIfTriggered($defender, $state);
    }

    private function applyEnemyCondition(BattleActor $defender, BattleState $state, string $key, int $turns, float $rate): void
    {
        $current = $defender->conditions[$key] ?? [];
        $rate = min(match ($key) {
            'def_down', 'slow' => 0.40,
            'recovery_block' => 0.50,
            default => 1.0,
        }, max((float) ($current['rate'] ?? 0), $rate));
        $turns = $defender->isPlayer
            ? app(ExplorationSupportService::class)->adjustedConditionDuration($turns, $state->explorationSupportSnapshot)
            : max(1, $turns);
        $defender->conditions[$key] = ['turns' => $turns, 'rate' => $rate];
        $labels = ['burn' => '火傷', 'bleed' => '出血', 'def_down' => 'DEF低下', 'slow' => '鈍足', 'recovery_block' => '回復阻害'];
        $state->addLog("<span class=\"battle-log-condition battle-log-condition-{$key}\">{$defender->name} は {$labels[$key]} 状態になった！</span>");
    }

    private function applyPoisonCondition(BattleActor $defender, BattleState $state, int $turns): void
    {
        $current = $defender->conditions['poison'] ?? [];
        $stacks = min(3, max(1, (int) ($current['stacks'] ?? 0) + 1));
        $turns = $defender->isPlayer
            ? app(ExplorationSupportService::class)->adjustedConditionDuration($turns, $state->explorationSupportSnapshot)
            : max(1, $turns);
        $defender->conditions['poison'] = ['turns' => $turns, 'stacks' => $stacks, 'rate' => $stacks * 0.01];
        $state->addLog("<span class=\"battle-log-condition battle-log-condition-poison\">{$defender->name} は毒{$stacks}段階になった！</span>");
    }

    private function tickPlayerConditionsAfterAction(BattleActor $player, BattleState $state, bool $dealtDamage): void
    {
        foreach (['burn', 'poison', 'bleed'] as $key) {
            $condition = $player->conditions[$key] ?? null;
            if (!is_array($condition) || (int) ($condition['turns'] ?? 0) <= 0 || ($key === 'bleed' && !$dealtDamage)) {
                continue;
            }
            $damage = max(1, (int) floor($player->maxHp * (float) ($condition['rate'] ?? 0)));
            $damage = app(ExplorationSupportService::class)->adjustedDotDamage($damage, $state->explorationSupportSnapshot);
            $this->applyResolvedDamage(null, $player, $state, $damage, DamageSourceType::DOT, $key);
            $this->tryExplorationSupportHerbal($player, $state);
            $labels = ['burn' => '火傷', 'poison' => '毒', 'bleed' => '出血'];
            $state->addLog("<span class=\"battle-log-dot battle-log-dot-{$key}\">{$labels[$key]}により、{$player->name} は {$damage} ダメージを受けた！</span>");
            $this->logGutsIfTriggered($player, $state);
        }

        foreach ($player->conditions as $key => $condition) {
            if (!is_array($condition) || !isset($condition['turns'])) {
                continue;
            }
            $condition['turns']--;
            if ($condition['turns'] <= 0) {
                unset($player->conditions[$key]);
            } else {
                $player->conditions[$key] = $condition;
            }
        }
    }

    /**
     * 魔法攻撃処理
     */
    protected function executeMagicalAttack(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        int $powerMultiplier = 100,
        ?int $overrideSpr = null,
        bool $skipHitCheck = false,
        DamageSourceType $sourceType = DamageSourceType::OTHER,
        int|string|null $sourceId = null,
        ?HitResult $hitResult = null,
        int $hitIndex = 1,
        int $hitCount = 1,
        ?Skill $jobArtSkill = null,
    ): void
    {
        // 魔法も回避される可能性がある前提（命中判定）
        if (!$skipHitCheck && !$this->isPveAttackHit($attacker, $defender, $state)) {
            $state->addLog("{$attacker->name} は魔法を唱えた！……しかし、{$defender->name} は抵抗した！");
            return;
        }

        $statOverrides = $jobArtSkill !== null
            ? $this->jobArtV2RoleEffectService->damageStatOverrides($attacker, $defender, $jobArtSkill)
            : ['attack' => null, 'def' => null, 'spr' => null];
        $damage = $this->damageCalculator->calculateMagicalDamage(
            $attacker,
            $defender,
            $powerMultiplier,
            false,
            $statOverrides['attack'],
            $statOverrides['spr'] ?? $overrideSpr,
        );
        $damage = $this->applyPveKillerDamage($damage, $attacker, $defender, $state);
        $damage = $this->jobArtV2FieldService->modifyDamage($attacker, $state, $damage, $sourceType);
        if ($sourceType === DamageSourceType::JOB_ART && $jobArtSkill !== null) {
            $damage = $this->jobArtV2RoleEffectService->modifyJobArtDamage($attacker, $state, $jobArtSkill, $damage);
        }
        $damageResult = $this->applyResolvedDamage(
            $attacker,
            $defender,
            $state,
            $damage,
            $sourceType,
            $sourceId,
            $hitResult,
            $hitIndex,
            $hitCount,
            true,
            'magical',
        );
        $damage = $damageResult?->requestedDamage ?? $damage;
        $this->tryExplorationSupportHerbal($defender, $state);
        $state->addDamageLog("{$attacker->name} の魔法攻撃！ {$defender->name} に <span class=\"text-purple-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
        $this->logGutsIfTriggered($defender, $state);
    }

    private function isPveAttackHit(BattleActor $attacker, BattleActor $defender, BattleState $state, int $skillAccuracy = 100): bool
    {
        $fieldDelta = $this->jobArtV2FieldService->accuracyDelta($attacker, $state);
        if ($attacker->isPlayer || ! $defender->isPlayer) {
            return $this->damageCalculator->isHit($attacker, $defender, $skillAccuracy, 0.5, 70, 98, $fieldDelta);
        }

        $enemy = $attacker->originalModel;
        $enemy?->loadMissing('area');
        $cityId = (int) ($enemy?->area?->city_id ?? 0);
        $minHitRate = $cityId >= 10
            ? self::PVE_ENEMY_LATE_MIN_HIT_RATE
            : self::PVE_ENEMY_MIN_HIT_RATE;

        return $this->damageCalculator->isHit(
            $attacker,
            $defender,
            $skillAccuracy,
            0.5,
            $minHitRate,
            99,
            $fieldDelta,
        );
    }

    /**
     * スキル（必殺技）の実行
     */
    protected function executeSkillAction(BattleActor $attacker, BattleActor $defender, BattleState $state, \App\Models\Skill $skill): void
    {
        $state->addLog("<span class=\"text-blue-600 font-bold\">【必殺技】{$attacker->name} の必殺技、{$skill->name} が発動！</span>");
        
        $hitCount = max(1, (int) $skill->hit_count);
        // 回復やサポート特化で攻撃しない場合
        if ((int) $skill->hit_count === 0 && in_array($skill->damage_type, ['heal', 'support'], true)) {
            $hitCount = 1; 
        }
        if ((int) $skill->extra_hit_chance_percent > 0 && random_int(1, 100) <= (int) $skill->extra_hit_chance_percent) {
            $hitCount++;
        }

        for ($i = 0; $i < $hitCount; $i++) {
            $damage = 0;
            $isCrit = false; // 必殺技では会心を出さない
            $skillPowerInt = max(0, (int) round((float) $skill->power_multiplier * 100));

            // 敵DEF無視効果
            $overrideDef = null;
            $overrideSpr = null;
            if ((int) $skill->def_ignore_percent > 0) {
                $overrideDef = (int) floor($defender->def * (1 - ((int) $skill->def_ignore_percent / 100)));
                $overrideSpr = (int) floor($defender->spr * (1 - ((int) $skill->def_ignore_percent / 100)));
            }

            if ((float) $skill->luk_power_rate > 0) {
                $skillPowerInt += (int) floor($attacker->effectiveLuk() * (float) $skill->luk_power_rate);
            }

            if ((float) $skill->power_multiplier > 0) {
                if (in_array($skill->damage_type, ['physical', 'gold', 'drop', 'support'], true)) {
                    $damage = $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $skillPowerInt, $isCrit, null, $overrideDef);
                } elseif ($skill->damage_type === 'magical') {
                    $damage = $this->damageCalculator->calculateMagicalDamage($attacker, $defender, $skillPowerInt, $isCrit, null, $overrideSpr);
                } elseif ($skill->damage_type === 'hybrid') {
                    $hybridAtk = $attacker->hybridAttackPower(
                        (string) $skill->hybrid_scaling,
                        $this->jobArtV2RoleEffectService->enabledFor($attacker),
                    );
                    $damage = $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $skillPowerInt, $isCrit, $hybridAtk, $overrideDef);
                }
            }

            // ダメージ適用
            if ($damage > 0) {
                $damage = $this->applyPveKillerDamage($damage, $attacker, $defender, $state);
                $damage = $this->jobArtV2FieldService->modifyDamage(
                    $attacker,
                    $state,
                    $damage,
                    $skill->isJobArt() ? DamageSourceType::JOB_ART : DamageSourceType::JOB_SKILL,
                );
                $damageResult = $this->applyResolvedDamage(
                    $attacker,
                    $defender,
                    $state,
                    $damage,
                    $skill->isJobArt() ? DamageSourceType::JOB_ART : DamageSourceType::JOB_SKILL,
                    (int) $skill->id,
                    null,
                    $i + 1,
                    $hitCount,
                    true,
                    $skill->damage_type === 'magical' ? 'magical' : 'physical',
                );
                $damage = $damageResult?->requestedDamage ?? $damage;
                $state->addDamageLog("{$defender->name} に <span class=\"text-red-600 font-extrabold text-lg\">{$damage}</span> のダメージ！");
                $this->logGutsIfTriggered($defender, $state);
            }

            if ($defender->isDead()) break;
        }

        if ((int) $skill->gold_bonus_percent > 0) {
            $state->goldBonusPercent = max($state->goldBonusPercent ?? 0, (int) $skill->gold_bonus_percent);
        }
        if ((int) $skill->drop_bonus_percent > 0) {
            $state->dropBonusPercent = max($state->dropBonusPercent ?? 0, (int) $skill->drop_bonus_percent);
        }
        if ((int) $skill->rare_bonus_percent > 0) {
            $state->rareBonusPercent = max($state->rareBonusPercent ?? 0, (int) $skill->rare_bonus_percent);
        }

        // 副効果の適用
        if ((int) $skill->heal_percent > 0) {
            $healAmount = (int) floor($attacker->maxHp * ((int) $skill->heal_percent / 100));
            $actualHeal = $this->jobArtV2FieldService->applyHpHeal($attacker, $state, $healAmount);
            $state->addLog("<span class=\"text-green-600 font-bold\">{$attacker->name} の傷が {$actualHeal} 回復した！</span>");
        }

        if ((int) $skill->mp_recover_percent > 0 && $attacker->maxMp > 0) {
            $mpHealAmount = (int) floor($attacker->maxMp * ((int) $skill->mp_recover_percent / 100) * (1 - $attacker->conditionRate('recovery_block')));
            $attacker->mp = min($attacker->maxMp, $attacker->mp + $mpHealAmount);
            $state->addLog("<span class=\"text-blue-500 font-bold\">{$attacker->name} はSPを {$mpHealAmount} 回復した！</span>");
        }

        if ((int) $skill->self_damage_percent > 0) {
            $selfDamage = (int) floor($attacker->maxHp * ((int) $skill->self_damage_percent / 100));
            $this->applyResolvedDamage(
                $attacker,
                $attacker,
                $state,
                $selfDamage,
                DamageSourceType::RECOIL,
                (int) $skill->id,
            );
            $state->addLog("<span class=\"text-purple-600 font-bold\">反動により、{$attacker->name} は {$selfDamage} のダメージを受けた！</span>");
            $this->jobArtV2ResourceService->recordSelfDamage($attacker, $state, $selfDamage);
            $this->logGutsIfTriggered($attacker, $state);
        }

        // デバフの適用（ボスは半減。単純化のため現在ステータスを直接下げる）
        $isBoss = isset($defender->originalModel->is_boss) ? $defender->originalModel->is_boss : false;
        $debuffRatio = $isBoss ? 0.5 : 1.0;
        
        $this->applyStructuredDebuffs($attacker, $defender, $state, $skill, $debuffRatio);

        // バフの適用
        if ((int) $skill->damage_reduction_percent > 0) {
            $state->addLog("{$attacker->name} は次の被ダメージを軽減する構えをとった！");
            $attacker->damageReductionRate = max($attacker->damageReductionRate, min(50, (int) $skill->damage_reduction_percent));
        }
        
        if ((int) $skill->self_buff_percent > 0) {
            $rate = (int) $skill->self_buff_percent / 100;
            $state->addLog("{$attacker->name} の攻撃力と魔法力が上昇した！");
            $attacker->str += (int) floor($attacker->baseStr * $rate);
            $attacker->mag += (int) floor($attacker->baseMag * $rate);
            $attacker->str = min($attacker->str, (int) floor($attacker->baseStr * 1.5)); // 上限1.5倍
            $attacker->mag = min($attacker->mag, (int) floor($attacker->baseMag * 1.5));
        }
    }

    private function applyStructuredDebuffs(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        float $rate = 1.0,
    ): bool
    {
        $timed = $this->jobArtV2RoleEffectService->applyTimedStructuredDebuffs(
            $attacker,
            $defender,
            $state,
            $skill,
            $rate,
        );
        if ($timed !== null) {
            foreach ($timed['changes'] as $change) {
                $state->addLog("{$defender->name} の{$change['label']}が {$change['percent']}% 低下した！（{$timed['duration_turns']}ターン）");
            }

            return $timed['changes'] !== [];
        }

        $applied = false;
        $debuffs = [
            'enemy_atk_down_percent' => ['prop' => 'str', 'base' => 'baseStr', 'label' => '攻撃力'],
            'enemy_mag_down_percent' => ['prop' => 'mag', 'base' => 'baseMag', 'label' => '魔法力'],
            'enemy_def_down_percent' => ['prop' => 'def', 'base' => 'baseDef', 'label' => '防御力'],
            'enemy_spr_down_percent' => ['prop' => 'spr', 'base' => 'baseSpr', 'label' => '精神力'],
            'enemy_spd_down_percent' => ['prop' => 'agi', 'base' => 'baseAgi', 'label' => '素早さ'],
        ];

        foreach ($debuffs as $field => $config) {
            $configured = (int) ($skill->{$field} ?? 0);
            if ($configured <= 0) {
                continue;
            }

            $effect = max(1, (int) floor($configured * $rate));
            $prop = $config['prop'];
            $base = $config['base'];
            $defender->{$prop} = max(1, $defender->{$prop} - (int) floor($defender->{$base} * ($effect / 100)));
            $state->addLog("{$defender->name} の{$config['label']}が {$effect}% 低下した！");
            $applied = true;
        }

        return $applied;
    }

    private function executeJobArtAction(BattleActor $attacker, BattleActor $defender, BattleState $state, Skill $skill): void
    {
        $sourceSkill = $skill;
        $skill = $this->jobArtV2CrownBalanceCatalog->applyToExecution($skill);
        $usesRoleEffects = $this->jobArtV2ResourceService->enabledFor($attacker);
        if ($usesRoleEffects
            || $this->jobArtV2DamageSemanticsResolver->forExecution($attacker, $sourceSkill) !== null
            || $this->jobArtV2EffectSemanticsResolver->suppressesLegacySelfBuff($attacker, $sourceSkill)
        ) {
            $skill = $this->jobArtV2CrownBalanceCatalog->applyToExecution($sourceSkill);
            $this->jobArtV2DamageSemanticsResolver->applyForExecution($attacker, $sourceSkill, $skill);
            $this->jobArtV2EffectSemanticsResolver->applyForExecution($attacker, $sourceSkill, $skill);
        }
        $skillId = (int) $skill->id;
        $rate = (float) ($attacker->jobArtRates[$skillId] ?? 1.0);
        $basePower = $this->jobArtV2PowerResolver->forExecution($attacker, $sourceSkill, $state);
        $power = max(0, (int) round(($basePower ?: 100) * $rate));

        if ((float) $skill->luk_power_rate > 0) {
            $power += max(0, (int) floor($attacker->effectiveLuk() * (float) $skill->luk_power_rate * $rate));
        }
        if ($usesRoleEffects) {
            // Keep the runtime-scaled power on the v2 execution clone only.
            // Legacy uses the master Skill plus its separate inheritance rate.
            $skill->power = $power;
            $skill->power_multiplier = max(0, $power / 100);
            $this->jobArtV2RoleEffectService->applyForExecution($attacker, $defender, $state, $sourceSkill, $skill);
            $this->jobArtV2PowerResolver->applyFinalDamageModifiers(
                $attacker,
                $sourceSkill,
                $skill,
                $state,
            );
            $this->jobArtV2UltimateCounterplayService->applyForExecution(
                $attacker,
                $defender,
                $state,
                $sourceSkill,
                $skill,
            );
        }
        $template = (string) $skill->effect_template;

        $state->jobArtUseCounts[$skillId] = (int) ($state->jobArtUseCounts[$skillId] ?? 0) + 1;
        if (! $this->jobArtV2FeatureGate->usesDynamicSingle($attacker)
            && (int) $skill->cooldown_turns > 0
        ) {
            $state->jobArtCooldowns[$skillId] = (int) $skill->cooldown_turns;
        }

        // Player-facing order follows the actual action: announce the art first,
        // then show cast-time resource changes, and finally HIT-time changes.
        $state->addLog($this->jobArtActivationLog($attacker, $defender, $skill));

        $this->jobArtV2UltimateCounterplayService->beginJobArtCast($attacker, $state, $sourceSkill);
        $this->jobArtV2ResourceService->applyJobArtCast($attacker, $state, $skill);
        if (! $this->jobArtV2UltimateCounterplayService
            ->lineageEffectsSuppressedForCurrentAction($attacker, $state, $sourceSkill)
        ) {
            $this->jobArtV2PenetrationStanceService->beginCast($attacker, $state, $skill);
            $this->jobArtV2DefenseService->applyJobArtCast($attacker, $state, $skill);
        }
        $this->jobArtV2RoleEffectService->beginJobArtCast($attacker, $state, $sourceSkill);

        if ($this->jobArtV2FieldService->isFieldOnlyArt($attacker, $state, $skill)) {
            $this->jobArtV2UltimateCounterplayService->completeJobArtCast(
                $attacker,
                $defender,
                $state,
                $sourceSkill,
                null,
            );
            $this->jobArtV2RoleEffectService->completeJobArtCast($attacker, $defender, $state, $sourceSkill, null);
            $this->jobArtV2PenetrationStanceService->completeCast($attacker, $state, $skill);
            return;
        }
        $hitResult = $this->jobArtActionResolver->resolveJobArt($attacker, $defender, $skill, $state->battleType, $state);
        $this->jobArtV2BattleHudService->recordHitResult($attacker, $state, $hitResult);
        if ($hitResult !== null && !$hitResult->landed()) {
            $state->addLog($this->jobArtResolutionFailureLog($skill, $hitResult));
            $this->applyMissedJobArtOnCastEffects($attacker, $defender, $state, $skill, $rate);
            $this->jobArtV2UltimateCounterplayService->completeJobArtCast(
                $attacker,
                $defender,
                $state,
                $sourceSkill,
                $hitResult,
            );
            $this->jobArtV2RoleEffectService->completeJobArtCast($attacker, $defender, $state, $sourceSkill, $hitResult);
            $this->jobArtV2PenetrationStanceService->completeCast($attacker, $state, $skill);

            return;
        }
        $skipHitCheck = $hitResult?->landed() ?? false;
        $beforeDefenderHp = $defender->hp;

        match ($template) {
            'MAGICAL_DAMAGE' => $this->executeJobArtDamageTemplate($attacker, $defender, $state, $skill, $power, 'magical', $skipHitCheck),
            'HYBRID_DAMAGE' => $this->executeHybridJobArtAttack($attacker, $defender, $state, $skill, $power, $skipHitCheck),
            'MULTI_HIT' => $this->executeMultiHitJobArt($attacker, $defender, $state, $skill, $power, $skipHitCheck),
            'DAMAGE_BUFF' => $this->executeDamageBuffJobArt($attacker, $defender, $state, $power, $skill, $skipHitCheck),
            'MAGICAL_DAMAGE_BUFF' => $this->executeMagicalDamageBuffJobArt($attacker, $defender, $state, $power, $skill, $skipHitCheck),
            'DAMAGE_DEBUFF' => $this->executeDamageDebuffJobArt($attacker, $defender, $state, $power, $skill, $skipHitCheck),
            'DAMAGE_GUARD_BARRIER' => $this->executeDamageGuardBarrierJobArt($attacker, $defender, $state, $power, $skill, $rate, $skipHitCheck),
            'SELF_BUFF' => $this->applySelfBuff($attacker, $state, $skill),
            'ENEMY_DEBUFF' => null,
            'GUARD_BARRIER' => $this->applyGuardBarrier($attacker, $state, $skill, $rate),
            // In v2 the execution clone already contains the inherited/runtime
            // power. Applying the inheritance rate again would double-scale it.
            'HEAL', 'HEAL_CLEANSE' => $this->applyJobArtHeal(
                $attacker,
                $state,
                $skill,
                $usesRoleEffects ? 1.0 : $rate,
            ),
            'DRAIN' => $this->executeDrainJobArt($attacker, $defender, $state, $power, $rate, $skill, $skipHitCheck),
            'GUTS' => $this->applyGuts($attacker, $state),
            'REWARD_GOLD', 'REWARD_DROP', 'REWARD_MIXED' => $this->applyRewardJobArt($state, $skill, $rate),
            'PHYSICAL_DAMAGE_REWARD', 'PHYSICAL_DAMAGE_GOLD_REWARD' => $this->executePhysicalDamageRewardJobArt($attacker, $defender, $state, $power, $skill, $rate, $skipHitCheck),
            'MAGICAL_DAMAGE_REWARD' => $this->executeMagicalDamageRewardJobArt($attacker, $defender, $state, $power, $skill, $rate, $skipHitCheck),
            'TIME_CONTROL_CURRENT_ONLY', 'V2_ROLE_EFFECT_ONLY' => null,
            default => $this->executeJobArtDamageTemplate($attacker, $defender, $state, $skill, $power, 'physical', $skipHitCheck),
        };

        $totalDamage = max(0, $beforeDefenderHp - $defender->hp);
        $this->applyJobArtStructuredSideEffects($attacker, $defender, $state, $skill, $totalDamage, $rate);
        if ($hitResult?->landed()) {
            $this->jobArtV2ResourceService->recordJobArtHit($attacker, $state, $skill);
        }
        $this->jobArtV2SpPressureService->applyOnHit($attacker, $defender, $state, $skill, $hitResult);
        $this->jobArtV2BreakDebuffService->applyOnHit($attacker, $defender, $state, $skill, $hitResult);
        $this->jobArtV2UltimateCounterplayService->completeJobArtCast(
            $attacker,
            $defender,
            $state,
            $sourceSkill,
            $hitResult,
        );
        $this->jobArtV2RoleEffectService->completeJobArtCast($attacker, $defender, $state, $sourceSkill, $hitResult);
        $this->jobArtV2PenetrationStanceService->completeCast($attacker, $state, $skill);
    }

    protected function endJobArtV2Round(BattleState $state): void
    {
        $this->jobArtV2FieldService->endRound($state);
        $this->jobArtV2BreakDebuffService->endRound($state);
        $this->jobArtV2DefenseService->endRound($state);
        $this->jobArtV2RoleEffectService->endRound($state);
    }

    private function executeMultiHitJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, Skill $skill, int $power, bool $skipHitCheck = false): void
    {
        $this->executeJobArtDamageTemplate(
            $attacker,
            $defender,
            $state,
            $skill,
            $power,
            $attacker->usesMagForNormalAttack() ? 'magical' : 'physical',
            $skipHitCheck,
        );
    }

    /** @return array{def: ?int, spr: ?int, penetration_rate: ?float} */
    private function jobArtDefenseOverrides(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
    ): array {
        if ($this->jobArtV2PenetrationStanceService->enabledFor($attacker)) {
            return $this->jobArtV2PenetrationStanceService->defenseOverrides($attacker, $defender, $state, $skill);
        }

        return $this->jobArtV2PenetrationService->defenseOverrides($attacker, $defender, $skill);
    }

    private function executeJobArtDamageTemplate(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        int $power,
        string $damageType,
        bool $skipHitCheck = false,
    ): void {
        $hits = max(1, (int) $skill->hit_count);
        $hitPowers = JobArtHitPower::split($power, $hits);
        $overrides = $this->jobArtDefenseOverrides($attacker, $defender, $state, $skill);
        $overrideDef = $overrides['def'];
        $overrideSpr = $overrides['spr'];

        foreach ($hitPowers as $i => $hitPower) {
            if ($damageType === 'magical') {
                $this->executeMagicalAttack(
                    $attacker,
                    $defender,
                    $state,
                    $hitPower,
                    $overrideSpr,
                    $skipHitCheck,
                    DamageSourceType::JOB_ART,
                    (int) $skill->id,
                    HitResult::HIT,
                    $i + 1,
                    $hits,
                    $skill,
                );
            } else {
                $this->executePhysicalAttack(
                    $attacker,
                    $defender,
                    $state,
                    $hitPower,
                    $overrideDef,
                    null,
                    $skipHitCheck,
                    DamageSourceType::JOB_ART,
                    (int) $skill->id,
                    HitResult::HIT,
                    $i + 1,
                    $hits,
                    $skill,
                );
            }

            if ($defender->isDead()) {
                break;
            }
        }
    }

    private function jobArtActivationLog(BattleActor $attacker, BattleActor $defender, Skill $skill): string
    {
        $titleClass = $this->jobArtFlavorTextService->activationTitleClass($skill);
        $lines = [
            '<span class="' . e($titleClass) . '">《' . e($skill->name) . '》が発動！</span>',
        ];

        $flavorText = $this->jobArtFlavorTextService->resolve($skill);
        $phrase = trim((string) ($flavorText['activation_phrase'] ?? ''));
        if ($phrase !== '') {
            $lines[] = '<span class="battle-log-special-phrase">' . e($this->formatJobArtFlavorText($phrase, $attacker, $defender, $skill)) . '</span>';
        }

        $description = trim((string) ($flavorText['activation_description'] ?? ''));
        if ($description !== '') {
            $lines[] = '<span class="battle-log-special-description">' . e($this->formatJobArtFlavorText($description, $attacker, $defender, $skill)) . '</span>';
        }

        return implode('<br>', $lines);
    }

    private function formatJobArtFlavorText(string $text, BattleActor $attacker, BattleActor $defender, Skill $skill): string
    {
        return strtr($text, [
            '{user}' => $attacker->name,
            '{target}' => $defender->name,
            '{skill}' => (string) $skill->name,
        ]);
    }

    private function jobArtResolutionFailureLog(Skill $skill, HitResult $result): string
    {
        $verb = $result === HitResult::EVADE ? '回避された' : '外れた';

        return '<span class="text-slate-600 font-bold">' . e((string) $skill->name) . "は{$verb}！</span>";
    }

    private function applyMissedJobArtOnCastEffects(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        float $rate,
    ): void {
        match ((string) $skill->effect_template) {
            'DAMAGE_BUFF' => $this->applySelfBuff($attacker, $state, $skill),
            'MAGICAL_DAMAGE_BUFF' => $this->applySelfBuff($attacker, $state, $skill, true),
            'DAMAGE_GUARD_BARRIER' => $this->applyGuardBarrier($attacker, $state, $skill, $rate),
            'PHYSICAL_DAMAGE_REWARD', 'PHYSICAL_DAMAGE_GOLD_REWARD', 'MAGICAL_DAMAGE_REWARD' => $this->applyRewardJobArt($state, $skill, $rate),
            default => null,
        };

        $this->applyJobArtStructuredSideEffects($attacker, $defender, $state, $skill, 0, $rate, false);
    }

    private function executeMagicalDamageRewardJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, Skill $skill, float $rate, bool $skipHitCheck = false): void
    {
        $this->executeJobArtDamageTemplate($attacker, $defender, $state, $skill, $power, 'magical', $skipHitCheck);
        $this->applyRewardJobArt($state, $skill, $rate);
    }

    private function executePhysicalDamageRewardJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, Skill $skill, float $rate, bool $skipHitCheck = false): void
    {
        $this->executeJobArtDamageTemplate($attacker, $defender, $state, $skill, $power, 'physical', $skipHitCheck);
        $this->applyRewardJobArt($state, $skill, $rate);
    }

    private function executeDamageGuardBarrierJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, Skill $skill, float $rate, bool $skipHitCheck = false): void
    {
        $this->executeJobArtDamageTemplate(
            $attacker,
            $defender,
            $state,
            $skill,
            $power,
            $attacker->usesMagForNormalAttack() ? 'magical' : 'physical',
            $skipHitCheck,
        );
        $this->applyGuardBarrier($attacker, $state, $skill, $rate);
    }

    private function executeHybridJobArtAttack(BattleActor $attacker, BattleActor $defender, BattleState $state, Skill $skill, int $power, bool $skipHitCheck = false): void
    {
        $hits = max(1, (int) $skill->hit_count);
        $hitPowers = JobArtHitPower::split($power, $hits);
        $hybridAtk = $attacker->hybridAttackPower(
            (string) $skill->hybrid_scaling,
            $this->jobArtV2RoleEffectService->enabledFor($attacker),
        );
        $overrides = $this->jobArtDefenseOverrides($attacker, $defender, $state, $skill);
        $overrideDef = $overrides['def'];

        foreach ($hitPowers as $i => $hitPower) {
            if (!$skipHitCheck && !$this->isPveAttackHit($attacker, $defender, $state)) {
                $state->addLog("{$attacker->name} の奥義！……しかし、{$defender->name} はかわした！");
                continue;
            }

            $damage = $this->damageCalculator->calculatePhysicalDamage($attacker, $defender, $hitPower, false, $hybridAtk, $overrideDef);
            $damage = $this->applyPveKillerDamage($damage, $attacker, $defender, $state);
            $damage = $this->jobArtV2FieldService->modifyDamage($attacker, $state, $damage, DamageSourceType::JOB_ART);
            $damage = $this->jobArtV2RoleEffectService->modifyJobArtDamage($attacker, $state, $skill, $damage);
            $damageResult = $this->applyResolvedDamage(
                $attacker,
                $defender,
                $state,
                $damage,
                DamageSourceType::JOB_ART,
                (int) $skill->id,
                HitResult::HIT,
                $i + 1,
                $hits,
                true,
                'physical',
            );
            $damage = $damageResult?->requestedDamage ?? $damage;
            $state->addDamageLog("{$defender->name} に <span class=\"text-fuchsia-600 font-extrabold text-lg\">{$damage}</span> の複合ダメージ！");
            $this->logGutsIfTriggered($defender, $state);
            if ($defender->isDead()) {
                break;
            }
        }
    }

    private function executeDamageBuffJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, Skill $skill, bool $skipHitCheck = false): void
    {
        $this->executeJobArtDamageTemplate(
            $attacker,
            $defender,
            $state,
            $skill,
            $power,
            $attacker->usesMagForNormalAttack() ? 'magical' : 'physical',
            $skipHitCheck,
        );
        if ($skipHitCheck || !$defender->isDead()) {
            $this->applySelfBuff($attacker, $state, $skill);
        }
    }

    private function executeMagicalDamageBuffJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, Skill $skill, bool $skipHitCheck = false): void
    {
        $this->executeJobArtDamageTemplate($attacker, $defender, $state, $skill, $power, 'magical', $skipHitCheck);
        if ($skipHitCheck || !$defender->isDead()) {
            $this->applySelfBuff($attacker, $state, $skill, true);
        }
    }

    private function executeDamageDebuffJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, Skill $skill, bool $skipHitCheck = false): void
    {
        $this->executeJobArtDamageTemplate(
            $attacker,
            $defender,
            $state,
            $skill,
            $power,
            $attacker->usesMagForNormalAttack() ? 'magical' : 'physical',
            $skipHitCheck,
        );
    }

    private function applySelfBuff(BattleActor $attacker, BattleState $state, Skill $skill, ?bool $forceMagical = null): void
    {
        $damageType = $forceMagical === null ? null : ($forceMagical ? 'magical' : 'physical');
        $shared = $this->jobArtV2RoleEffectService->applySharedSelfBuff($attacker, $state, $skill, $damageType);
        if ($shared !== null) {
            $this->logStatChange(
                $state,
                $attacker->name,
                $shared['main_label'],
                $shared['main_before'],
                $shared['main_after'],
                $shared['sub_label'],
                $shared['sub_before'],
                $shared['sub_after'],
                true,
            );

            return;
        }

        $rate = $this->buffRate($skill);
        $isMagical = $forceMagical ?? $attacker->usesMagForNormalAttack();
        if ($isMagical) {
            $beforeMain = $attacker->mag;
            $beforeSub = $attacker->spr;
            $attacker->mag = min((int) floor($attacker->baseMag * 1.5), $attacker->mag + max(1, (int) floor($attacker->baseMag * $rate)));
            $attacker->spr = min((int) floor($attacker->baseSpr * 1.5), $attacker->spr + max(1, (int) floor($attacker->baseSpr * ($rate / 2))));
            $this->logStatChange($state, $attacker->name, 'MAG', $beforeMain, $attacker->mag, 'SPR', $beforeSub, $attacker->spr, true);
        } else {
            $beforeMain = $attacker->str;
            $beforeSub = $attacker->def;
            $attacker->str = min((int) floor($attacker->baseStr * 1.5), $attacker->str + max(1, (int) floor($attacker->baseStr * $rate)));
            $attacker->def = min((int) floor($attacker->baseDef * 1.5), $attacker->def + max(1, (int) floor($attacker->baseDef * ($rate / 2))));
            $this->logStatChange($state, $attacker->name, 'ATK', $beforeMain, $attacker->str, 'DEF', $beforeSub, $attacker->def, true);
        }
    }

    private function applyEnemyDebuff(BattleActor $defender, BattleState $state, Skill $skill): void
    {
        $rate = $this->buffRate($skill);
        $beforeDef = $defender->def;
        $beforeSpr = $defender->spr;
        $defender->def = max(1, $defender->def - max(1, (int) floor($defender->baseDef * $rate)));
        $defender->spr = max(1, $defender->spr - max(1, (int) floor($defender->baseSpr * ($rate / 2))));
        $this->logStatChange($state, $defender->name, 'DEF', $beforeDef, $defender->def, 'SPR', $beforeSpr, $defender->spr, false);
    }

    private function logStatChange(
        BattleState $state,
        string $actorName,
        string $mainLabel,
        int $mainBefore,
        int $mainAfter,
        string $subLabel,
        int $subBefore,
        int $subAfter,
        bool $isBuff
    ): void {
        $mainPct = $mainBefore > 0 ? (int) round((abs($mainAfter - $mainBefore) / $mainBefore) * 100) : 0;
        $subPct = $subBefore > 0 ? (int) round((abs($subAfter - $subBefore) / $subBefore) * 100) : 0;

        if ($mainAfter === $mainBefore && $subAfter === $subBefore) {
            $color = $isBuff ? 'text-indigo-600' : 'text-violet-700';
            $verb = $isBuff ? '強化' : '弱体化';
            $state->addLog("<span class=\"{$color} font-bold\">{$actorName} はこれ以上{$verb}できない！</span>");
            return;
        }

        $color = $isBuff ? 'text-indigo-600' : 'text-violet-700';
        $direction = $isBuff ? '上昇' : '低下';
        $state->addLog("<span class=\"{$color} font-bold\">{$actorName} の{$mainLabel}が {$mainPct}% / {$subLabel}が {$subPct}% {$direction}した！</span>");
    }

    private function hasStructuredDebuff(Skill $skill): bool
    {
        return (int) $skill->enemy_atk_down_percent > 0
            || (int) $skill->enemy_mag_down_percent > 0
            || (int) $skill->enemy_def_down_percent > 0
            || (int) $skill->enemy_spr_down_percent > 0
            || (int) $skill->enemy_spd_down_percent > 0;
    }

    private function applyGuardBarrier(BattleActor $attacker, BattleState $state, Skill $skill, float $rate = 1.0): void
    {
        $reduction = $this->jobArtGuardReduction($skill, $rate);
        $attacker->damageReductionRate = max($attacker->damageReductionRate, $reduction);
        $state->addLog("<span class=\"text-blue-700 font-bold\">{$attacker->name} は次の被ダメージを {$reduction}% 軽減する！</span>");
    }

    private function jobArtGuardReduction(Skill $skill, float $rate = 1.0): int
    {
        $base = (int) $skill->damage_reduction_percent > 0
            ? (int) $skill->damage_reduction_percent
            : min(50, max(10, (int) floor(((int) $skill->power ?: 100) / 10)));

        return min(50, max(1, (int) floor($base * $rate)));
    }

    private function applyJobArtHeal(BattleActor $attacker, BattleState $state, Skill $skill, float $rate): void
    {
        $power = max(1, (int) ($skill->power ?: 100));
        $healingSpr = $this->jobArtV2RoleEffectService->enabledFor($attacker)
            ? $attacker->effectiveSpr()
            : $attacker->spr;
        $heal = max(1, (int) floor($healingSpr * ($power / 100) * $rate));
        $actualHeal = $this->jobArtV2FieldService->applyHpHeal($attacker, $state, $heal);
        $state->addLog("<span class=\"text-emerald-600 font-bold\">HPが {$actualHeal} 回復した！</span>");
    }

    private function executeDrainJobArt(BattleActor $attacker, BattleActor $defender, BattleState $state, int $power, float $rate, Skill $skill, bool $skipHitCheck = false): void
    {
        $this->executeJobArtDamageTemplate(
            $attacker,
            $defender,
            $state,
            $skill,
            $power,
            JobArtEffectCatalog::drainDamageType($skill->damage_type),
            $skipHitCheck,
        );
    }

    private function recoverJobArtSp(BattleActor $attacker, BattleState $state, Skill $skill, float $rate): void
    {
        if ((int) $skill->mp_recover_percent <= 0 || $attacker->maxMp <= 0) {
            return;
        }

        $recover = max(1, (int) floor($attacker->maxMp * ((int) $skill->mp_recover_percent / 100) * $rate * (1 - $attacker->conditionRate('recovery_block'))));
        $before = $attacker->mp;
        $attacker->mp = min($attacker->maxMp, $attacker->mp + $recover);
        $actual = $attacker->mp - $before;

        if ($actual > 0) {
            $state->addLog("<span class=\"text-blue-500 font-bold\">SPが {$actual} 回復した！</span>");
        }
    }

    private function applyGuts(BattleActor $attacker, BattleState $state): void
    {
        $attacker->gutsReady = true;
        $state->addLog("<span class=\"text-orange-700 font-bold\">{$attacker->name} は一度だけ踏みとどまる覚悟を固めた！</span>");
    }

    private function logGutsIfTriggered(BattleActor $actor, BattleState $state): void
    {
        if (!$actor->gutsJustTriggered) {
            return;
        }

        $actor->gutsJustTriggered = false;
        $state->addLog("<span class=\"text-orange-700 font-extrabold\">{$actor->name} は不屈の精神で致死ダメージを耐えた！（HP1）</span>");
    }

    private function applyJobArtStructuredSideEffects(
        BattleActor $attacker,
        BattleActor $defender,
        BattleState $state,
        Skill $skill,
        int $totalDamage,
        float $rate,
        bool $applyTargetEffects = true,
    ): void {
        $template = (string) $skill->effect_template;

        if (!in_array($template, ['HEAL', 'HEAL_CLEANSE'], true) && (int) $skill->heal_percent > 0) {
            $heal = max(1, (int) floor($attacker->maxHp * ((int) $skill->heal_percent / 100) * $rate));
            $actualHeal = $this->jobArtV2FieldService->applyHpHeal($attacker, $state, $heal);
            $state->addLog("<span class=\"text-emerald-600 font-bold\">HPが {$actualHeal} 回復した！</span>");
        }

        $this->recoverJobArtSp($attacker, $state, $skill, $rate);

        if ((int) $skill->self_damage_percent > 0) {
            $selfDamage = max(1, (int) floor($attacker->maxHp * ((int) $skill->self_damage_percent / 100) * $rate));
            $this->applyResolvedDamage(
                $attacker,
                $attacker,
                $state,
                $selfDamage,
                DamageSourceType::RECOIL,
                (int) $skill->id,
            );
            $state->addLog("<span class=\"text-purple-600 font-bold\">反動により、{$attacker->name} は {$selfDamage} のダメージを受けた！</span>");
            $this->jobArtV2ResourceService->recordSelfDamage($attacker, $state, $selfDamage);
            $this->logGutsIfTriggered($attacker, $state);
        }

        if ((int) $skill->damage_reduction_percent > 0 && ! in_array($template, ['GUARD_BARRIER', 'DAMAGE_GUARD_BARRIER'], true)) {
            $reduction = min(50, max(1, (int) floor((int) $skill->damage_reduction_percent * $rate)));
            $attacker->damageReductionRate = max($attacker->damageReductionRate, $reduction);
            $state->addLog("<span class=\"text-blue-700 font-bold\">{$attacker->name} は次の被ダメージを {$reduction}% 軽減する！</span>");
        }

        $appliedDebuff = false;
        if ($applyTargetEffects) {
            $isBoss = (bool) ($defender->originalModel->is_boss ?? false);
            $debuffRate = $rate * ($isBoss ? 0.5 : 1.0);
            $appliedDebuff = $this->applyStructuredDebuffs($attacker, $defender, $state, $skill, $debuffRate);
        }

        if (
            $applyTargetEffects
            && !$appliedDebuff
            && !$defender->isDead()
            && in_array($template, ['ENEMY_DEBUFF', 'DAMAGE_DEBUFF'], true)
        ) {
            $this->applyEnemyDebuff($defender, $state, $skill);
        }
        if ($applyTargetEffects && !$appliedDebuff && !$defender->isDead() && $template === 'TIME_CONTROL_CURRENT_ONLY') {
            $this->applyTimeControl($defender, $state, $skill);
        }

        if ($template === 'DRAIN' && $totalDamage > 0 && (float) $skill->drain_hp_rate > 0) {
            $heal = max(1, (int) floor($totalDamage * (float) $skill->drain_hp_rate * $rate));
            $actualHeal = $this->jobArtV2FieldService->applyHpHeal($attacker, $state, $heal);
            $state->addLog("<span class=\"text-emerald-600 font-bold\">与えた力を吸収し、HPが {$actualHeal} 回復した！</span>");
        }
    }

    private function applyRewardJobArt(BattleState $state, Skill $skill, float $rate): void
    {
        $scope = (string) $skill->reward_scope;
        $base = max(1, (int) floor(((int) $skill->power ?: 100) / 20));
        $fallbackBonus = max(1, (int) floor($base * $rate));

        if (in_array($scope, ['gold', 'mixed'], true) || JobArtEffectCatalog::appliesGoldBonus((string) $skill->effect_template)) {
            $goldBonus = $this->rewardBonusForBattle((int) $skill->gold_bonus_percent, $fallbackBonus, $rate);
            $state->goldBonusPercent = min(10, max($state->goldBonusPercent, $goldBonus));
        }
        if (in_array($scope, ['drop', 'material', 'mixed'], true) || JobArtEffectCatalog::appliesDropBonus((string) $skill->effect_template)) {
            $dropBonus = $this->rewardBonusForBattle((int) $skill->drop_bonus_percent, $fallbackBonus, $rate);
            $state->dropBonusPercent = min(8, max($state->dropBonusPercent, $dropBonus));
            $rareBonus = (int) $skill->rare_bonus_percent > 0
                ? $this->rewardBonusForBattle((int) $skill->rare_bonus_percent, $fallbackBonus, $rate)
                : (int) floor($dropBonus / 2);
            $state->rareBonusPercent = min(8, max($state->rareBonusPercent, $rareBonus));
        }

        $state->addLog("<span class=\"text-amber-700 font-bold\">探索勝利時の報酬判定が少し良くなった！</span>");
    }

    private function rewardBonusForBattle(int $configuredBonus, int $fallbackBonus, float $rate): int
    {
        if ($configuredBonus <= 0) {
            return $fallbackBonus;
        }

        return max(1, (int) floor($configuredBonus * $rate));
    }

    private function applyTimeControl(BattleActor $defender, BattleState $state, Skill $skill): void
    {
        $rate = (int) $skill->enemy_spd_down_percent > 0
            ? (int) $skill->enemy_spd_down_percent / 100
            : max(0.05, $this->buffRate($skill));
        $before = $defender->agi;
        $defender->agi = max(1, $defender->agi - max(1, (int) floor($defender->baseAgi * $rate)));
        $pct = $before > 0 ? (int) round((abs($before - $defender->agi) / $before) * 100) : 0;
        $state->addLog("<span class=\"text-sky-700 font-bold\">{$defender->name} のSPDが {$pct}% 低下した！</span>");
    }

    private function buffRate(Skill $skill): float
    {
        $power = (int) ($skill->power ?: 100);
        if ($power >= 200) {
            return 0.20;
        }
        if ($power >= 140) {
            return 0.15;
        }
        return 0.10;
    }

    private function applyPveKillerDamage(int $damage, BattleActor $attacker, BattleActor $defender, BattleState $state): int
    {
        if ($damage <= 0) {
            return $damage;
        }

        if (!in_array($state->battleType, ['pve', 'boss'], true)) {
            return $damage;
        }

        if ($attacker->isPlayer && !$defender->isPlayer) {
            $defenderSpeciesKeys = $defender->speciesKeys;
            $rate = array_sum(array_map(
                static fn (array $effect): float => in_array($effect['species_key'], $defenderSpeciesKeys, true)
                    ? (float) $effect['damage_rate']
                    : 0.0,
                $attacker->weaponKillerEffects,
            ));
            $rate = min(
                (float) config('equipment_affix.weapon_killer_damage_rate_cap', 0.55),
                $rate,
            );
            if ($defenderSpeciesKeys !== [] && $rate > 0) {
                $damage = max(1, (int) floor($damage * (1 + $rate)));
            }
        }

        if (!$attacker->isPlayer && $defender->isPlayer) {
            $attackerSpeciesKeys = $attacker->speciesKeys;
            $resistSpecies = (string) ($defender->armorResistSpeciesKey ?? '');
            $rate = min(
                app(EquipmentAffixRulesService::class)->armorResistDamageReductionCap(),
                (float) ($defender->armorSpeciesDamageReductionRate ?? 0)
            );
            if ($resistSpecies !== '' && $rate > 0 && in_array($resistSpecies, $attackerSpeciesKeys, true)) {
                $damage = max(1, (int) floor($damage * (1 - $rate)));
            }
        }

        if ($defender->isPlayer) {
            $damage = app(ExplorationSupportService::class)->reduceDirectDamage($damage, $state->explorationSupportSnapshot);
        }

        return $damage;
    }

    private function tryExplorationSupportHerbal(BattleActor $actor, BattleState $state): void
    {
        if (!$actor->isPlayer || !$actor->originalModel instanceof Character || $actor->gutsJustTriggered) {
            return;
        }

        $heal = app(ExplorationSupportService::class)->trySpecialHerbal(
            $actor->originalModel,
            $actor->hp,
            $actor->maxHp,
            $state->explorationSupportSnapshot,
        );
        if ($heal !== null) {
            $state->addLog("<span class=\"text-emerald-700 font-bold\">【薬屋の特製漢方】{$actor->name} は {$heal} 回復した！</span>");
        }
    }
}
