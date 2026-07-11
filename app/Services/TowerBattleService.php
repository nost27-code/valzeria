<?php

namespace App\Services;

use App\Models\Character;
use App\Models\Enemy;
use App\Models\TowerMerchantPurchase;
use App\Models\TowerFloorMaster;
use App\Models\TowerRun;
use App\Models\TowerRunEvent;
use App\Services\Battle\BattleActor;
use App\Services\Battle\BattleState;
use App\Services\Battle\DamageCalculator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TowerBattleService extends BattleService
{
    private const REQUEST_GUARD_SECONDS = 3;

    public function __construct(
        CharacterStatusService $statusService,
        DamageCalculator $damageCalculator,
        JobArtService $jobArtService,
        private readonly TowerEnemyScalingService $enemyScalingService,
        private readonly StarTreeTowerService $towerService,
        private readonly ExplorationStaminaService $staminaService,
        private readonly TowerMerchantService $merchantService,
        private readonly TowerTitleRewardService $titleRewardService,
        private readonly StarTreeTowerRewardService $rewardService,
        private readonly LevelService $levelService,
    ) {
        parent::__construct($statusService, $damageCalculator, $jobArtService);
    }

    public function requestGuardSeconds(): int
    {
        return self::REQUEST_GUARD_SECONDS;
    }

    public function actionStrategies(): array
    {
        return [
            'normal' => [
                'key' => 'normal',
                'name' => '通常に進む',
                'summary' => 'いつも通りに次の階へ挑む',
                'stamina_extra' => 0,
                'battle' => true,
            ],
            'cautious' => [
                'key' => 'cautious',
                'name' => '慎重に進む',
                'summary' => '探索力+1。敵の先制と痛恨を少し抑える',
                'stamina_extra' => 1,
                'battle' => true,
            ],
            'full_force' => [
                'key' => 'full_force',
                'name' => '全力で突破',
                'summary' => 'SPを少し消費。勝利時EXPが少し増える',
                'stamina_extra' => 0,
                'battle' => true,
            ],
            'breathe' => [
                'key' => 'breathe',
                'name' => '息を整える',
                'summary' => '探索力+1。戦闘前にHP/SPを回復するが敵も少し強まる',
                'stamina_extra' => 1,
                'battle' => true,
            ],
            'scout' => [
                'key' => 'scout',
                'name' => '様子を見る',
                'summary' => '探索力1を使い、次の敵タイプを確認する。戦闘はしない',
                'stamina_extra' => 0,
                'fixed_stamina_cost' => 1,
                'battle' => false,
            ],
        ];
    }

    public function stanceChoices(): array
    {
        $buffRate = max(0, (int) config('star_tree_tower.star_tree.stance_buff_rate', 2));
        $debuffRate = max(0, (int) config('star_tree_tower.star_tree.stance_debuff_rate', 1));

        return [
            'attack' => [
                'key' => 'attack',
                'name' => '攻めの構え',
                'summary' => "ATK/MAG +{$buffRate}%、DEF/SPR -{$debuffRate}%",
                'modifiers' => [
                    'str' => $buffRate,
                    'mag' => $buffRate,
                    'def' => -$debuffRate,
                    'spr' => -$debuffRate,
                ],
            ],
            'guard' => [
                'key' => 'guard',
                'name' => '守りの構え',
                'summary' => "DEF/SPR +{$buffRate}%、SPD -{$debuffRate}%",
                'modifiers' => [
                    'def' => $buffRate,
                    'spr' => $buffRate,
                    'agi' => -$debuffRate,
                ],
            ],
            'speed' => [
                'key' => 'speed',
                'name' => '疾風の構え',
                'summary' => "SPD/LUK +{$buffRate}%、ATK/MAG -{$debuffRate}%",
                'modifiers' => [
                    'agi' => $buffRate,
                    'luk' => $buffRate,
                    'str' => -$debuffRate,
                    'mag' => -$debuffRate,
                ],
            ],
            'none' => [
                'key' => 'none',
                'name' => '構えなし',
                'summary' => '能力補正を選ばず、このまま進む',
                'modifiers' => [],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function stanceState(TowerRun $run): array
    {
        $stance = (array) (($run->metadata ?? [])['stance'] ?? []);
        $totals = [];
        foreach ((array) ($stance['totals'] ?? []) as $key => $rate) {
            if (in_array($key, ['str', 'def', 'agi', 'mag', 'spr', 'luk'], true)) {
                $totals[$key] = (int) $rate;
            }
        }

        return [
            'selected' => array_values((array) ($stance['selected'] ?? [])),
            'totals' => $totals,
            'display_totals' => $this->formatModifierTotals($totals),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function pendingStance(TowerRun $run): ?array
    {
        $pendingFloor = (int) (($run->metadata ?? [])['stance']['pending_floor'] ?? 0);
        if ($pendingFloor <= 0) {
            return null;
        }

        return [
            'floor' => $pendingFloor,
            'choices' => $this->stanceChoices(),
            'state' => $this->stanceState($run),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function chooseStance(Character $character, TowerRun $run, string $stanceKey): array
    {
        if ((int) $run->character_id !== (int) $character->id) {
            throw new InvalidArgumentException('Tower run does not belong to the character.');
        }

        if ($run->status !== StarTreeTowerService::STATUS_RUNNING) {
            throw new InvalidArgumentException('Tower run is not running.');
        }

        $pending = $this->pendingStance($run);
        if (!$pending) {
            throw new RuntimeException('今は星樹の構えを選べません。');
        }

        $choices = $this->stanceChoices();
        $choice = $choices[$stanceKey] ?? null;
        if (!$choice) {
            throw new InvalidArgumentException('Unknown tower stance.');
        }

        $metadata = (array) ($run->metadata ?? []);
        $stance = (array) ($metadata['stance'] ?? []);
        $selected = array_values((array) ($stance['selected'] ?? []));
        $totals = (array) ($stance['totals'] ?? []);
        $floor = (int) $pending['floor'];

        foreach ($selected as $selectedStance) {
            if ((int) ($selectedStance['floor'] ?? 0) === $floor) {
                throw new RuntimeException('この階の構えはすでに選択済みです。');
            }
        }

        foreach ((array) ($choice['modifiers'] ?? []) as $key => $rate) {
            $totals[$key] = (int) ($totals[$key] ?? 0) + (int) $rate;
        }

        $selected[] = [
            'floor' => $floor,
            'key' => (string) $choice['key'],
            'name' => (string) $choice['name'],
            'summary' => (string) $choice['summary'],
            'modifiers' => (array) ($choice['modifiers'] ?? []),
            'selected_at' => now()->toDateTimeString(),
        ];

        $metadata['stance'] = [
            'selected' => $selected,
            'totals' => $totals,
        ];

        $run->forceFill(['metadata' => $metadata])->save();
        $run->refresh();

        return [
            'floor' => $floor,
            'choice' => $choice,
            'state' => $this->stanceState($run),
        ];
    }

    public function challengeCurrentFloor(Character $character, TowerRun $run, bool $acquireRequestGuard = true, string $strategy = 'normal'): TowerRunEvent
    {
        $this->assertRun($character, $run);

        $floorMaster = $this->floorForRun($run);
        $strategySpec = $this->strategySpec($strategy);
        if ($acquireRequestGuard) {
            $this->acquireBattleRequestGuard($character);
        }

        if (($strategySpec['key'] ?? 'normal') === 'scout') {
            return $this->scoutCurrentFloor($character, $run, $floorMaster, $strategySpec);
        }

        $staminaCost = $this->staminaCostForStrategy($floorMaster, $strategySpec);
        $stamina = $this->staminaService->consume(
            $character,
            $staminaCost,
            $this->towerService->displayText('name', '星樹の塔').'へ挑むための探索力が足りません。回復を待ってください。'
        );

        if (!($stamina['ok'] ?? false)) {
            throw new RuntimeException((string) ($stamina['error'] ?? '探索力が足りません。'));
        }

        $preBattle = $this->applyPreBattleStrategy($run, $strategySpec);
        $ward = $this->consumeNextBattleWard($run);
        $battleStartStatus = [
            'hp' => (int) $run->tower_current_hp,
            'max_hp' => (int) $run->tower_max_hp,
            'sp' => (int) $run->tower_current_mp,
            'max_sp' => (int) $run->tower_max_mp,
        ];
        $battle = $this->runBattle($character, $run, $floorMaster, $strategySpec, $ward);
        $battleStartStatus['stats'] = $battle['player_start_stats'];
        $battle['logs'] = array_values(array_filter([
            ...$preBattle['logs'],
            ...($ward['logs'] ?? []),
            ...$battle['logs'],
        ]));
        $result = $battle['result'];
        $hpAfter = $battle['player_hp_after'];
        $mpAfter = $battle['player_mp_after'];

        if ($result === 'victory') {
            $reward = $this->grantVictoryReward($character, $floorMaster, (int) ($strategySpec['exp_bonus_rate'] ?? 0));
            $recovery = $this->applyWinRecovery($run, $hpAfter, $mpAfter);
            $hpAfter = $recovery['hp_after'];
            $mpAfter = $recovery['mp_after'];
            $battle['logs'][] = $this->winRecoveryLog($recovery);
            $updatedRun = $this->towerService->recordFloorCleared(
                $character,
                $run,
                (int) $floorMaster->floor,
                $hpAfter,
                $mpAfter,
                (int) ($stamina['consumed'] ?? 0)
            );
            $this->merchantService->maybeReserveAfterVictory($character, $updatedRun);
            $unlockedTitles = $this->titleRewardService->unlockFloorMilestones($character, (int) $updatedRun->cleared_floor);
            $pendingRewards = $this->rewardService->createPendingRewardsForClearedFloor($character, (int) $updatedRun->cleared_floor, (string) $updatedRun->tower_key);
            $updatedRun = $this->openPendingStanceChoiceIfNeeded($updatedRun);
        } else {
            $reward = ['exp_gained' => 0, 'job_exp_gained' => 0, 'result' => null];
            $updatedRun = $this->towerService->finishAsDefeated(
                $character,
                $run,
                (int) $floorMaster->floor,
                $hpAfter,
                $mpAfter
            );
            $updatedRun->increment('stamina_spent', (int) ($stamina['consumed'] ?? 0));
            $updatedRun->refresh();
            $unlockedTitles = [];
            $pendingRewards = [];
        }

        return TowerRunEvent::query()->create([
            'tower_run_id' => $updatedRun->id,
            'character_id' => $character->id,
            'floor' => (int) $floorMaster->floor,
            'event_type' => 'battle',
            'result' => $result,
            'enemy_name' => $floorMaster->enemy_name,
            'enemy_profile' => $floorMaster->enemy_profile,
            'damage_taken' => max(0, (int) $run->tower_current_hp - $hpAfter),
            'hp_after' => $hpAfter,
            'mp_after' => $mpAfter,
            'stamina_delta' => (int) ($stamina['consumed'] ?? 0),
            'exp_gained' => $reward['exp_gained'],
            'job_exp_gained' => $reward['job_exp_gained'],
            'message' => $this->battleMessage($result, $floorMaster),
            'metadata' => [
                'logs' => $battle['logs'],
                'enemy_stats' => $battle['enemy_stats'],
                'enemy_base_stats' => $battle['enemy_base_stats'],
                'player_start_stats' => $battle['player_start_stats'],
                'player_base_stats' => $battle['player_base_stats'],
                'turn_count' => $battle['turn_count'],
                'cleared_floor' => $updatedRun->cleared_floor,
                'current_floor' => $updatedRun->current_floor,
                'reward_result' => $reward['result'],
                'unlocked_titles' => $unlockedTitles,
                'pending_rewards' => $pendingRewards,
                'strategy' => $strategySpec,
                'pre_battle' => $preBattle,
                'battle_start_status' => $battleStartStatus,
                'ward' => $ward,
                'stance' => $this->stanceState($updatedRun),
                'pending_stance' => $this->pendingStance($updatedRun),
            ],
        ]);
    }

    private function floorForRun(TowerRun $run): TowerFloorMaster
    {
        $floor = TowerFloorMaster::query()
            ->where('tower_key', $run->tower_key)
            ->where('floor', (int) $run->current_floor)
            ->where('is_active', true)
            ->first();

        if (!$floor) {
            throw new RuntimeException($this->towerService->displayText('name', '星樹の塔').'の階層マスタが見つかりません。');
        }

        return $floor;
    }

    /**
     * @return array{result:string,logs:array<int,string>,turn_count:int,player_hp_after:int,player_mp_after:int,enemy_stats:array<string,int>,enemy_base_stats:array<string,int>,player_start_stats:array<string,int>,player_base_stats:array<string,int>}
     */
    private function runBattle(
        Character $character,
        TowerRun $run,
        TowerFloorMaster $floorMaster,
        array $strategySpec = [],
        ?array $ward = null,
    ): array
    {
        $playerBaseStats = [];
        $player = $this->makePlayerActor($character, $run, $playerBaseStats);
        $enemyBaseStats = $this->enemyScalingService->statsForFloorMaster($floorMaster);
        $enemyStats = $this->applyBattleModifiers(
            $enemyBaseStats,
            $strategySpec,
            $ward
        );
        $enemyBaseStats['mp'] = 0;
        $enemyBaseStats['max_mp'] = 0;
        $enemyStats['mp'] = 0;
        $enemyStats['max_mp'] = 0;
        $enemyModel = new Enemy([
            'name' => (string) $floorMaster->enemy_name,
            'type_name' => $floorMaster->enemy_type_name ?: '標準型',
            'species_key' => $this->towerEnemySpeciesKey($floorMaster),
            'is_boss' => false,
        ]);

        $enemy = new BattleActor((string) $floorMaster->enemy_name, false, [
            'hp' => $enemyStats['max_hp'],
            'max_hp' => $enemyStats['max_hp'],
            'mp' => 0,
            'max_mp' => 0,
            'str' => $enemyStats['str'],
            'def' => $enemyStats['def'],
            'agi' => $enemyStats['agi'],
            'mag' => $enemyStats['mag'],
            'spr' => $enemyStats['spr'],
            'luk' => $enemyStats['luk'],
            'species_key' => $this->towerEnemySpeciesKey($floorMaster),
        ], $enemyModel);

        $state = new BattleState($player, $enemy, 'pve');
        if (($strategySpec['key'] ?? 'normal') === 'cautious') {
            $state->addLog('<span style="color:#047857;font-weight:900;">足音を潜めて進み、相手の気勢を削いだ。</span>');
        }
        if (($strategySpec['key'] ?? 'normal') === 'breathe') {
            $state->addLog('<span style="color:#0d9488;font-weight:900;">息を整えた気配に呼応して、'.e($this->towerService->displayText('generic_enemy_name', '星樹の魔物')).'も力を増した。</span>');
        }
        $state->addLog('【'.$this->towerService->displayText('log_label', '星樹の塔')."】{$floorMaster->floor}階、{$enemy->name} が立ちはだかった！");

        while (!$state->isBattleEnded()) {
            $state->turnCount++;
            $state->addLog("<br><br>--- ターン {$state->turnCount} ---");

            $playerSpeed = $player->agi + random_int(0, 5);
            $enemySpeed = $enemy->agi + random_int(0, 5);

            if ($playerSpeed >= $enemySpeed) {
                $this->executeAction($player, $enemy, $state);
                if ($state->isBattleEnded()) {
                    break;
                }
                $this->executeAction($enemy, $player, $state);
            } else {
                $this->executeAction($enemy, $player, $state);
                if ($state->isBattleEnded()) {
                    break;
                }
                $this->executeAction($player, $enemy, $state);
            }
        }

        $result = match (true) {
            $enemy->isDead() => 'victory',
            $player->isDead() => 'defeat',
            default => 'timeout',
        };

        return [
            'result' => $result,
            'logs' => $state->logs,
            'turn_count' => $state->turnCount,
            'player_hp_after' => max(0, $player->hp),
            'player_mp_after' => max(0, $player->mp),
            'enemy_stats' => $enemyStats,
            'enemy_base_stats' => $enemyBaseStats,
            'player_start_stats' => [
                'max_hp' => (int) $player->maxHp,
                'max_mp' => (int) $player->maxMp,
                'str' => (int) $player->str,
                'def' => (int) $player->def,
                'agi' => (int) $player->agi,
                'mag' => (int) $player->mag,
                'spr' => (int) $player->spr,
                'luk' => (int) $player->luk,
            ],
            'player_base_stats' => $playerBaseStats,
        ];
    }

    private function makePlayerActor(Character $character, TowerRun $run, ?array &$playerBaseStats = null): BattleActor
    {
        app(EquipmentAutoUnequipService::class)->unequipInvalidItems($character);
        $character->refresh();
        $stats = $this->statusService->getFinalStats($character);
        $playerBaseStats = [
            'max_hp' => (int) ($stats['max_hp'] ?? $run->tower_max_hp),
            'max_mp' => (int) ($stats['max_mp'] ?? $run->tower_max_mp),
            'str' => (int) ($stats['str'] ?? 1),
            'def' => (int) ($stats['def'] ?? 0),
            'agi' => (int) ($stats['agi'] ?? 1),
            'mag' => (int) ($stats['mag'] ?? 0),
            'spr' => (int) ($stats['spr'] ?? 0),
            'luk' => (int) ($stats['luk'] ?? 0),
        ];
        $stats = $this->applyStanceModifiersToStats($stats, $run);
        $equippedWeapon = $character->characterItems()
            ->where('is_equipped', true)
            ->whereHas('item', fn ($query) => $query->where('type', 'weapon'))
            ->with('item')
            ->first();
        $equippedArmor = $character->characterItems()
            ->where('is_equipped', true)
            ->whereHas('item', fn ($query) => $query->where('type', 'armor'))
            ->with('item')
            ->first();

        $currentJob = $character->current_job_id
            ? $character->currentJob()->with('skill')->first()
            : null;

        $actor = new BattleActor($character->name, true, [
            'hp' => min((int) $run->tower_current_hp, (int) ($stats['max_hp'] ?? $run->tower_max_hp)),
            'max_hp' => (int) ($stats['max_hp'] ?? $run->tower_max_hp),
            'mp' => min((int) $run->tower_current_mp, (int) ($stats['max_mp'] ?? $run->tower_max_mp)),
            'max_mp' => (int) ($stats['max_mp'] ?? $run->tower_max_mp),
            'str' => (int) ($stats['str'] ?? 1),
            'def' => (int) ($stats['def'] ?? 0),
            'agi' => (int) ($stats['agi'] ?? 1),
            'mag' => (int) ($stats['mag'] ?? 0),
            'spr' => (int) ($stats['spr'] ?? 0),
            'luk' => (int) ($stats['luk'] ?? 0),
            'normal_attack_type' => $currentJob?->normal_attack_type,
            'weapon_killer_species_key' => $equippedWeapon?->killer_species_key,
            'weapon_killer_damage_rate' => (float) ($equippedWeapon?->killer_damage_rate ?? 0),
            'armor_resist_species_key' => $equippedArmor?->resist_species_key,
            'armor_species_damage_reduction_rate' => (float) ($equippedArmor?->species_damage_reduction_rate ?? 0),
        ], clone $character);

        if ($currentJob) {
            if ($currentJob->skill) {
                $actor->skill = $currentJob->skill;
            }
            $actor->jobKey = $currentJob->key;
        }

        $actor->jobArtActivationPolicy = (string) ($character->job_art_activation_policy ?: 'normal');
        $jobArts = $this->jobArtService->battleArtsFor($character, 'pve');
        $actor->jobArts = $jobArts->all();
        foreach ($jobArts as $art) {
            $actor->jobArtRates[(int) $art->id] = (float) $art->getAttribute('job_art_rate');
            $actor->jobArtOrigins[(int) $art->id] = (string) $art->getAttribute('job_art_origin');
            $actor->jobArtPolicies[(int) $art->id] = (string) ($art->getAttribute('job_art_activation_policy') ?: $actor->jobArtActivationPolicy);
        }

        return $actor;
    }

    public function acquireBattleRequestGuard(Character $character): void
    {
        $now = now(config('app.timezone', 'Asia/Tokyo'));
        $availableBefore = $now->copy()->subSeconds(self::REQUEST_GUARD_SECONDS);
        $updated = Character::query()
            ->whereKey($character->id)
            ->where(function ($query) use ($availableBefore): void {
                $query
                    ->whereNull('last_battle_at')
                    ->orWhere('last_battle_at', '<=', $availableBefore);
            })
            ->update([
                'last_battle_at' => $now,
                'updated_at' => $now,
            ]);

        if ($updated < 1) {
            throw new RuntimeException('探索処理中です。少し待ってからもう一度お試しください。');
        }

        $character->forceFill(['last_battle_at' => $now]);
    }

    private function towerEnemySpeciesKey(TowerFloorMaster $floorMaster): string
    {
        return match ((string) ($floorMaster->enemy_type_name ?: $floorMaster->enemy_profile ?: '')) {
            '植物' => 'plant',
            '獣' => 'beast',
            '小鬼' => 'goblin',
            'スライム' => 'slime',
            '虫' => 'insect',
            '飛行' => 'flying',
            '精霊', '妖精' => 'spirit',
            default => (string) ($floorMaster->enemy_type_name ?: $floorMaster->enemy_profile ?: ''),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function strategySpec(string $strategy): array
    {
        $strategies = $this->actionStrategies();
        $spec = $strategies[$strategy] ?? $strategies['normal'];

        if (($spec['key'] ?? 'normal') === 'full_force') {
            $spec['sp_cost_rate'] = max(0, (int) config('star_tree_tower.star_tree.strategy_full_sp_cost_rate', 8));
            $spec['exp_bonus_rate'] = max(0, (int) config('star_tree_tower.star_tree.strategy_full_exp_bonus_rate', 15));
        }

        if (($spec['key'] ?? 'normal') === 'breathe') {
            $spec['recover_rate'] = max(0, (int) config('star_tree_tower.star_tree.strategy_breathe_recover_rate', 10));
            $spec['enemy_power_rate'] = max(0, (int) config('star_tree_tower.star_tree.strategy_breathe_enemy_power_rate', 5));
        }

        if (($spec['key'] ?? 'normal') === 'cautious') {
            $spec['enemy_agi_luk_down_rate'] = max(0, min(90, (int) config('star_tree_tower.star_tree.strategy_cautious_enemy_agi_luk_down_rate', 15)));
        }

        return $spec;
    }

    /**
     * @param array<string,mixed> $strategySpec
     */
    private function staminaCostForStrategy(TowerFloorMaster $floorMaster, array $strategySpec): int
    {
        if (isset($strategySpec['fixed_stamina_cost'])) {
            return max(1, (int) $strategySpec['fixed_stamina_cost']);
        }

        return max(0, (int) $floorMaster->stamina_cost + (int) ($strategySpec['stamina_extra'] ?? 0));
    }

    /**
     * @param array<string,mixed> $strategySpec
     */
    private function scoutCurrentFloor(
        Character $character,
        TowerRun $run,
        TowerFloorMaster $floorMaster,
        array $strategySpec,
    ): TowerRunEvent {
        if ($this->hasScoutedFloor($run, (int) $floorMaster->floor)) {
            throw new RuntimeException('この階の様子はすでに見ています。戦い方を選んで進んでください。');
        }

        $staminaCost = $this->staminaCostForStrategy($floorMaster, $strategySpec);
        $stamina = $this->staminaService->consume(
            $character,
            $staminaCost,
            $this->towerService->displayText('name', '星樹の塔').'の様子を見るための探索力が足りません。回復を待ってください。'
        );

        if (!($stamina['ok'] ?? false)) {
            throw new RuntimeException((string) ($stamina['error'] ?? '探索力が足りません。'));
        }

        $enemyStats = $this->enemyScalingService->statsForFloorMaster($floorMaster);
        $run->forceFill([
            'stamina_spent' => (int) $run->stamina_spent + (int) ($stamina['consumed'] ?? 0),
            'last_event_type' => 'scout',
        ])->save();
        $run->refresh();

        return TowerRunEvent::query()->create([
            'tower_run_id' => $run->id,
            'character_id' => $character->id,
            'floor' => (int) $floorMaster->floor,
            'event_type' => 'scout',
            'result' => 'scouted',
            'enemy_name' => $floorMaster->enemy_name,
            'enemy_profile' => $floorMaster->enemy_profile,
            'damage_taken' => 0,
            'hp_after' => $run->tower_current_hp,
            'mp_after' => $run->tower_current_mp,
            'stamina_delta' => (int) ($stamina['consumed'] ?? 0),
            'message' => "{$floorMaster->floor}階の気配を探った。",
            'metadata' => [
                'logs' => [
                    '<span style="color:#0f766e;font-weight:900;">'.e($this->towerService->displayText('scout_flavor', '星樹の気配を読み、次に立ちはだかる相手を見定めた。')).'</span>',
                    "{$floorMaster->floor}階には、{$floorMaster->enemy_name} が待ち構えている。",
                ],
                'enemy_stats' => $enemyStats,
                'current_floor' => $run->current_floor,
                'cleared_floor' => $run->cleared_floor,
                'strategy' => $strategySpec,
            ],
        ]);
    }

    private function hasScoutedFloor(TowerRun $run, int $floor): bool
    {
        return TowerRunEvent::query()
            ->where('tower_run_id', $run->id)
            ->where('event_type', 'scout')
            ->where('floor', $floor)
            ->exists();
    }

    private function openPendingStanceChoiceIfNeeded(TowerRun $run): TowerRun
    {
        $clearedFloor = (int) $run->cleared_floor;
        if (! $this->isStanceFloor($clearedFloor)) {
            return $run;
        }

        $metadata = (array) ($run->metadata ?? []);
        $stance = (array) ($metadata['stance'] ?? []);
        if ((int) ($stance['pending_floor'] ?? 0) === $clearedFloor) {
            return $run;
        }

        foreach ((array) ($stance['selected'] ?? []) as $selectedStance) {
            if ((int) ($selectedStance['floor'] ?? 0) === $clearedFloor) {
                return $run;
            }
        }

        $stance['pending_floor'] = $clearedFloor;
        $metadata['stance'] = $stance;
        $run->forceFill(['metadata' => $metadata])->save();
        $run->refresh();

        return $run;
    }

    private function isStanceFloor(int $floor): bool
    {
        $startFloor = max(1, (int) config('star_tree_tower.star_tree.stance_start_floor', 50));
        $interval = max(1, (int) config('star_tree_tower.star_tree.stance_interval', 5));
        $maxFloor = max($startFloor, (int) config('star_tree_tower.star_tree.seed_floor_count', 100));

        return $floor >= $startFloor
            && $floor < $maxFloor
            && (($floor - $startFloor) % $interval) === 0;
    }

    /**
     * @param array<string,int|float> $stats
     * @return array<string,int|float>
     */
    private function applyStanceModifiersToStats(array $stats, TowerRun $run): array
    {
        $totals = (array) ($this->stanceState($run)['totals'] ?? []);
        foreach (['str', 'def', 'agi', 'mag', 'spr', 'luk'] as $key) {
            $rate = (int) ($totals[$key] ?? 0);
            if ($rate === 0) {
                continue;
            }

            $base = max(0, (int) ($stats[$key] ?? 0));
            $adjusted = $rate > 0
                ? (int) ceil($base * (100 + $rate) / 100)
                : (int) floor($base * (100 + $rate) / 100);
            $stats[$key] = max($key === 'luk' ? 0 : 1, $adjusted);
        }

        return $stats;
    }

    /**
     * @param array<string,int> $totals
     * @return array<int,array{key:string,label:string,rate:int,text:string}>
     */
    private function formatModifierTotals(array $totals): array
    {
        $labels = [
            'str' => 'ATK',
            'def' => 'DEF',
            'agi' => 'SPD',
            'mag' => 'MAG',
            'spr' => 'SPR',
            'luk' => 'LUK',
        ];
        $result = [];

        foreach ($labels as $key => $label) {
            $rate = (int) ($totals[$key] ?? 0);
            if ($rate === 0) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => $label,
                'rate' => $rate,
                'text' => $label.($rate > 0 ? '+' : '').$rate.'%',
            ];
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $strategySpec
     * @return array{hp_recovered:int,mp_recovered:int,sp_spent:int,logs:array<int,string>}
     */
    private function applyPreBattleStrategy(TowerRun $run, array $strategySpec): array
    {
        $result = [
            'hp_recovered' => 0,
            'mp_recovered' => 0,
            'sp_spent' => 0,
            'logs' => [],
        ];

        if (($strategySpec['key'] ?? 'normal') === 'full_force') {
            $spCost = min(
                (int) $run->tower_current_mp,
                max(1, (int) floor((int) $run->tower_max_mp * (int) ($strategySpec['sp_cost_rate'] ?? 0) / 100))
            );

            if ($spCost > 0) {
                $run->forceFill(['tower_current_mp' => max(0, (int) $run->tower_current_mp - $spCost)])->save();
                $result['sp_spent'] = $spCost;
                $result['logs'][] = '<span style="color:#b45309;font-weight:900;">全力で突破するため、SPを'.number_format($spCost).'消費した。</span>';
            }
        }

        if (($strategySpec['key'] ?? 'normal') === 'breathe') {
            $rate = (int) ($strategySpec['recover_rate'] ?? 0);
            $hpRecover = min(
                max(0, (int) $run->tower_max_hp - (int) $run->tower_current_hp),
                max(1, (int) floor((int) $run->tower_max_hp * $rate / 100))
            );
            $mpRecover = min(
                max(0, (int) $run->tower_max_mp - (int) $run->tower_current_mp),
                max(1, (int) floor((int) $run->tower_max_mp * $rate / 100))
            );

            if ($hpRecover > 0 || $mpRecover > 0) {
                $run->forceFill([
                    'tower_current_hp' => min((int) $run->tower_max_hp, (int) $run->tower_current_hp + $hpRecover),
                    'tower_current_mp' => min((int) $run->tower_max_mp, (int) $run->tower_current_mp + $mpRecover),
                ])->save();
                $result['hp_recovered'] = $hpRecover;
                $result['mp_recovered'] = $mpRecover;
                $result['logs'][] = '<span style="color:#0d9488;font-weight:900;">息を整え、HPが'.number_format($hpRecover).'、SPが'.number_format($mpRecover).'回復した。</span>';
            }
        }

        if ($result['hp_recovered'] > 0 || $result['mp_recovered'] > 0 || $result['sp_spent'] > 0) {
            $run->refresh();
        }

        return $result;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function consumeNextBattleWard(TowerRun $run): ?array
    {
        $purchase = TowerMerchantPurchase::query()
            ->where('tower_run_id', $run->id)
            ->where('effect_type', 'damage_reduction_next')
            ->whereNotNull('activated_at')
            ->whereNull('used_at')
            ->oldest('id')
            ->first();

        if (!$purchase) {
            return null;
        }

        $purchase->forceFill(['used_at' => now()])->save();
        $rate = max(1, min(80, (int) $purchase->effect_value));

        return [
            'purchase_id' => (int) $purchase->id,
            'name' => (string) $purchase->item_name,
            'damage_reduction_rate' => $rate,
            'logs' => [
                '<span style="color:#047857;font-weight:900;">'.e((string) $purchase->item_name).'が淡く輝き、この戦闘の被ダメージを'.$rate.'%軽減する。</span>',
            ],
        ];
    }

    /**
     * @param array<string,int> $enemyStats
     * @param array<string,mixed> $strategySpec
     * @param array<string,mixed>|null $ward
     * @return array<string,int>
     */
    private function applyBattleModifiers(array $enemyStats, array $strategySpec, ?array $ward): array
    {
        if (($strategySpec['key'] ?? 'normal') === 'cautious') {
            $rate = (int) ($strategySpec['enemy_agi_luk_down_rate'] ?? 0);
            foreach (['agi', 'luk'] as $key) {
                $enemyStats[$key] = max(1, (int) floor((int) ($enemyStats[$key] ?? 1) * (100 - $rate) / 100));
            }
        }

        if (($strategySpec['key'] ?? 'normal') === 'breathe') {
            $rate = (int) ($strategySpec['enemy_power_rate'] ?? 0);
            foreach (['max_hp', 'str', 'def', 'mag', 'spr', 'agi'] as $key) {
                $base = max(1, (int) ($enemyStats[$key] ?? 1));
                $enemyStats[$key] = max(1, (int) ceil($base * (100 + $rate) / 100));
            }
        }

        if ($ward) {
            $rate = (int) ($ward['damage_reduction_rate'] ?? 0);
            foreach (['str', 'mag'] as $key) {
                $enemyStats[$key] = max(0, (int) floor((int) ($enemyStats[$key] ?? 0) * (100 - $rate) / 100));
            }
        }

        return $enemyStats;
    }

    /**
     * @return array{hp_after:int,mp_after:int,hp_recovered:int,mp_recovered:int,hp_rate:int,mp_rate:int}
     */
    private function applyWinRecovery(TowerRun $run, int $hpAfter, int $mpAfter): array
    {
        $hpRate = max(0, (int) config('star_tree_tower.star_tree.win_recover_hp_rate', 5));
        $mpRate = max(0, (int) config('star_tree_tower.star_tree.win_recover_mp_rate', 5));

        $hp = min((int) $run->tower_max_hp, $hpAfter + (int) floor((int) $run->tower_max_hp * $hpRate / 100));
        $mp = min((int) $run->tower_max_mp, $mpAfter + (int) floor((int) $run->tower_max_mp * $mpRate / 100));

        return [
            'hp_after' => $hp,
            'mp_after' => $mp,
            'hp_recovered' => max(0, $hp - $hpAfter),
            'mp_recovered' => max(0, $mp - $mpAfter),
            'hp_rate' => $hpRate,
            'mp_rate' => $mpRate,
        ];
    }

    /**
     * @param array{hp_recovered:int,mp_recovered:int,hp_rate:int,mp_rate:int} $recovery
     */
    private function winRecoveryLog(array $recovery): string
    {
        $parts = [];
        if ($recovery['hp_recovered'] > 0) {
            $parts[] = 'HPが'.number_format($recovery['hp_recovered']);
        }
        if ($recovery['mp_recovered'] > 0) {
            $parts[] = 'SPが'.number_format($recovery['mp_recovered']);
        }

        if ($parts === []) {
            return '<span style="color:#0d9488;font-weight:900;">'.e($this->towerService->displayText('breath_name', '星樹の息吹')).'が巡ったが、HP/SPは満ちている。</span>';
        }

        return '<span style="color:#0d9488;font-weight:900;">'.e($this->towerService->displayText('breath_name', '星樹の息吹')).'が巡り、'.implode('、', $parts).'回復した。</span>';
    }

    /**
     * @return array{exp_gained:int,job_exp_gained:int,result:?array}
     */
    private function grantVictoryReward(Character $character, TowerFloorMaster $floorMaster, int $expBonusRate = 0): array
    {
        $exp = max(0, (int) config('star_tree_tower.star_tree.victory_exp_base', 20))
            + (max(1, (int) $floorMaster->floor) * max(0, (int) config('star_tree_tower.star_tree.victory_exp_per_floor', 5)));
        if ($expBonusRate > 0 && $exp > 0) {
            $exp += max(1, (int) floor($exp * $expBonusRate / 100));
        }
        $jobExp = $this->levelService->capJobExpGain((int) config('star_tree_tower.star_tree.victory_job_exp', 1));

        return [
            'exp_gained' => $exp,
            'job_exp_gained' => $jobExp,
            'result' => $this->levelService->addRewardAndCheckLevelUp($character, $exp, 0, $jobExp),
        ];
    }

    private function battleMessage(string $result, TowerFloorMaster $floorMaster): string
    {
        return match ($result) {
            'victory' => $this->towerService->displayText('name', '星樹の塔')." {$floorMaster->floor}階を突破した。",
            'timeout' => $this->towerService->displayText('name', '星樹の塔')." {$floorMaster->floor}階で戦い切れず、挑戦を終えた。",
            default => $this->towerService->displayText('name', '星樹の塔')." {$floorMaster->floor}階で力尽きた。",
        };
    }

    private function assertRun(Character $character, TowerRun $run): void
    {
        if ((int) $run->character_id !== (int) $character->id) {
            throw new InvalidArgumentException('Tower run does not belong to the character.');
        }

        if ($run->status !== StarTreeTowerService::STATUS_RUNNING) {
            throw new InvalidArgumentException('Tower run is not running.');
        }

        if ($run->pending_event === TowerMerchantService::PENDING_EVENT) {
            throw new RuntimeException($this->towerService->displayText('merchant_pending_message', '星灯の行商人が待っています。購入するか、見送ってから次の階へ進んでください。'));
        }

        if ($this->pendingStance($run)) {
            throw new RuntimeException('星樹の構えを選んでから次の階へ進んでください。');
        }
    }
}
